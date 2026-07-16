<?php

declare(strict_types=1);

/*
 * This file is part of the NovoSGA project.
 *
 * (c) Rogerio Lino <rogeriolino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConfigurarAtendimentoUsuario
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $localId = null,
        #[Assert\NotNull]
        #[Assert\Positive]
        public ?int $numeroLocal = null,
    ) {
    }
}
