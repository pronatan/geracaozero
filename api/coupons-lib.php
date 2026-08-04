<?php
/**
 * Cupons: DynamoDB (admin) + fallback env GZ_COUPONS=CODE:pct,...
 */

function gz_coupons_table(): string
{
    return gz_env('DDB_COUPONS_TABLE', 'gz_coupons');
}

function gz_coupon_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', trim($code)));
}

function gz_normalize_coupon(array $c): array
{
    $code = gz_coupon_normalize((string) ($c['code'] ?? ''));
    $percent = (float) ($c['percent'] ?? 0);
    $maxUses = (int) ($c['maxUses'] ?? 0);
    $usedCount = (int) ($c['usedCount'] ?? 0);
    return [
        'code' => $code,
        'percent' => $percent,
        'active' => array_key_exists('active', $c) ? (bool) $c['active'] : true,
        'maxUses' => $maxUses,
        'usedCount' => $usedCount,
        'expiresAt' => (string) ($c['expiresAt'] ?? ''),
        'note' => (string) ($c['note'] ?? ''),
        'createdAt' => (string) ($c['createdAt'] ?? ''),
        'updatedAt' => (string) ($c['updatedAt'] ?? ''),
    ];
}

function gz_coupons_all(bool $activeOnly = false): array
{
    $rows = [];
    try {
        foreach (gz_ddb_scan_all(gz_coupons_table(), 200) as $row) {
            $n = gz_normalize_coupon($row);
            if ($n['code'] === '') {
                continue;
            }
            if ($activeOnly && !$n['active']) {
                continue;
            }
            $rows[] = $n;
        }
    } catch (Throwable $e) {
        // tabela ausente → fallback env
    }
    usort($rows, function ($a, $b) {
        return strcmp($a['code'], $b['code']);
    });
    return $rows;
}

function gz_coupon_by_code(string $code): ?array
{
    $code = gz_coupon_normalize($code);
    if ($code === '') {
        return null;
    }
    try {
        $row = gz_ddb_get(gz_coupons_table(), ['code' => $code]);
        if ($row) {
            return gz_normalize_coupon($row);
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

function gz_save_coupon(array $coupon): bool
{
    $n = gz_normalize_coupon($coupon);
    if ($n['code'] === '' || $n['percent'] < 1 || $n['percent'] > 90) {
        return false;
    }
    $n['updatedAt'] = date('c');
    if ($n['createdAt'] === '') {
        $n['createdAt'] = $n['updatedAt'];
    }
    return (bool) gz_ddb_put(gz_coupons_table(), $n);
}

function gz_delete_coupon(string $code): bool
{
    $code = gz_coupon_normalize($code);
    if ($code === '') {
        return false;
    }
    return (bool) gz_ddb_delete(gz_coupons_table(), ['code' => $code]);
}

function gz_coupon_increment_uses(string $code): void
{
    $c = gz_coupon_by_code($code);
    if (!$c) {
        return;
    }
    $c['usedCount'] = (int) ($c['usedCount'] ?? 0) + 1;
    gz_save_coupon($c);
}

/** Fallback env map */
function gz_coupons_map_env(): array
{
    $raw = trim(gz_env('GZ_COUPONS', ''));
    $out = [];
    if ($raw === '') {
        return $out;
    }
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || strpos($part, ':') === false) {
            continue;
        }
        [$code, $pct] = array_map('trim', explode(':', $part, 2));
        $code = gz_coupon_normalize($code);
        $pct = (float) $pct;
        if ($code === '' || $pct < 1 || $pct > 90) {
            continue;
        }
        $out[$code] = $pct;
    }
    return $out;
}

/**
 * @return array{ok:bool,code?:string,percent?:float,message?:string}
 */
function gz_coupon_validate(string $code): array
{
    $code = gz_coupon_normalize($code);
    if ($code === '') {
        return ['ok' => false, 'message' => 'Informe o cupom'];
    }

    $c = gz_coupon_by_code($code);
    if ($c) {
        if (!$c['active']) {
            return ['ok' => false, 'message' => 'Cupom inativo'];
        }
        if ($c['expiresAt'] !== '') {
            $exp = strtotime($c['expiresAt']);
            if ($exp && $exp < time()) {
                return ['ok' => false, 'message' => 'Cupom expirado'];
            }
        }
        if ($c['maxUses'] > 0 && $c['usedCount'] >= $c['maxUses']) {
            return ['ok' => false, 'message' => 'Cupom esgotado'];
        }
        return [
            'ok' => true,
            'code' => $c['code'],
            'percent' => (float) $c['percent'],
            'message' => $c['percent'] . '% de desconto',
        ];
    }

    $map = gz_coupons_map_env();
    if (!isset($map[$code])) {
        return ['ok' => false, 'message' => 'Cupom inválido'];
    }
    return [
        'ok' => true,
        'code' => $code,
        'percent' => (float) $map[$code],
        'message' => $map[$code] . '% de desconto',
    ];
}

function gz_coupon_apply_amount(float $amount, float $percent): string
{
    $discounted = $amount * (1 - ($percent / 100));
    if ($discounted < 1) {
        $discounted = 1.0;
    }
    return number_format($discounted, 2, '.', '');
}
