<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        // "replay" runs the whole application against a captured world: no AzerothCore, no
        // MySQL, no game client. It is how someone who has just cloned the repository sees the
        // thing work. See the `when@replay` block in config/services.yaml.
        return ['prod', 'dev', 'test', 'replay'];
    }
}
