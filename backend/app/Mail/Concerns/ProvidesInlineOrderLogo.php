<?php

namespace App\Mail\Concerns;

trait ProvidesInlineOrderLogo
{
    protected function inlineOrderLogoPath(): ?string
    {
        $path = dirname(base_path()).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'logo.png';

        return is_file($path) && !is_link($path) && is_readable($path) ? $path : null;
    }
}
