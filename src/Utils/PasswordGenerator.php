<?php
declare(strict_types=1);

namespace EsxiV9\Utils;

class PasswordGenerator
{
    public function __invoke()
    {
        return hash('sha512', random_bytes(16));
    }
}