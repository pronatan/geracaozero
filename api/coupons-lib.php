<?php
/**
 * Cupons via env: GZ_COUPONS=STAFF10:10,EVENT15:15
 * Formato: CODIGO:percentual (1-90)
 */

function gz_coupons_map(): array
{
    $raw = trim(gz_env('GZ_COUPONS', 'STAFF10:10,EVENT15:15'));
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
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $code));
        $pct = (float) $pct;
        if ($code === '' || $pct < 1 || $pct > 90) {
            continue;
        }
        $out[$code] = $pct;
    }
    return $out;
}

function gz_coupon_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', trim($code)));
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
    $map = gz_coupons_map();
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
