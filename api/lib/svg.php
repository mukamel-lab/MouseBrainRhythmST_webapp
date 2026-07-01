<?php

declare(strict_types=1);

function svg_error_message(string $title, string $message = '', int $width = 900, int $height = 360): string
{
    $title = xml_escape($title);
    $message = xml_escape($message);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="img">'
        . '<rect width="100%" height="100%" fill="white"/>'
        . '<rect x="20" y="20" width="' . ($width - 40) . '" height="' . ($height - 40) . '" rx="10" fill="#f8fafc" stroke="#cbd5e1"/>'
        . '<text x="' . ($width / 2) . '" y="' . ($height / 2 - 10) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="22" font-weight="700" fill="#1f2937">' . $title . '</text>'
        . ($message !== '' ? '<text x="' . ($width / 2) . '" y="' . ($height / 2 + 25) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" fill="#64748b">' . $message . '</text>' : '')
        . '</svg>';
}

function nice_number(float $value, bool $round): float
{
    if ($value <= 0 || !is_finite($value)) {
        return 1.0;
    }
    $exponent = floor(log10($value));
    $fraction = $value / pow(10, $exponent);
    if ($round) {
        if ($fraction < 1.5) $niceFraction = 1;
        elseif ($fraction < 3) $niceFraction = 2;
        elseif ($fraction < 7) $niceFraction = 5;
        else $niceFraction = 10;
    } else {
        if ($fraction <= 1) $niceFraction = 1;
        elseif ($fraction <= 2) $niceFraction = 2;
        elseif ($fraction <= 5) $niceFraction = 5;
        else $niceFraction = 10;
    }
    return $niceFraction * pow(10, $exponent);
}

function nice_ticks(float $min, float $max, int $count = 5): array
{
    if (!is_finite($min) || !is_finite($max)) {
        return array(0.0, 1.0);
    }
    if ($min === $max) {
        $padding = abs($min) > 0 ? abs($min) * 0.1 : 1.0;
        $min -= $padding;
        $max += $padding;
    }
    $range = nice_number($max - $min, false);
    $spacing = nice_number($range / max(1, $count - 1), true);
    $niceMin = floor($min / $spacing) * $spacing;
    $niceMax = ceil($max / $spacing) * $spacing;
    $ticks = array();
    for ($x = $niceMin, $guard = 0; $x <= $niceMax + $spacing * 0.5 && $guard < 100; $x += $spacing, $guard++) {
        $ticks[] = abs($x) < 1e-12 ? 0.0 : $x;
    }
    return $ticks;
}

function svg_numeric_label(float $value): string
{
    $abs = abs($value);
    if ($abs > 0 && ($abs < 0.01 || $abs >= 10000)) {
        return preg_replace('/e([+-])0+/', 'e$1', sprintf('%.1e', $value));
    }
    if ($abs >= 10) {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function svg_polyline(array $points, string $stroke, float $width = 1.5, float $opacity = 1.0): string
{
    if (count($points) < 2) {
        return '';
    }
    $pairs = array();
    foreach ($points as $point) {
        $pairs[] = round((float) $point[0], 2) . ',' . round((float) $point[1], 2);
    }
    return '<polyline fill="none" stroke="' . xml_escape($stroke) . '" stroke-width="' . $width . '" stroke-opacity="' . $opacity . '" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $pairs) . '"/>';
}
