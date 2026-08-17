<?php
declare(strict_types=1);

namespace Salvest;

interface AccessTokenProvider
{
    public function accessToken():string;
}
