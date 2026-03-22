<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlaceholderController
{
    public function show(Request $request, int $width, int $height): Response
    {
        $width = max(1, min($width, 2000));
        $height = max(1, min($height, 2000));
        $label = $width . 'x' . $height;

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}" role="img" aria-label="Placeholder {$label}">
  <rect width="100%" height="100%" fill="#EEF2F7"/>
  <rect x="1" y="1" width="{$width}" height="{$height}" fill="none" stroke="#CBD5E1" stroke-width="2"/>
  <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#64748B" font-family="Arial, sans-serif" font-size="14">{$label}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
