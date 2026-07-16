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

final readonly class CriarUsuario
{
    /**
     * @param UsuarioLotacao[] $lotacoes
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 30)]
        #[Assert\Regex(pattern: '/^[a-zA-Z0-9.\-_]+$/')]
        public ?string $login = null,
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 20)]
        public ?string $nome = null,
        #[Assert\NotNull]
        #[Assert\Length(max: 100)]
        public ?string $sobrenome = null,
        #[Assert\Email]
        #[Assert\Length(max: 150)]
        public ?string $email = null,
        #[Assert\NotBlank]
        #[Assert\Length(min: 6)]
        public ?string $senha = null,
        public bool $admin = false,
        #[Assert\Valid]
        public array $lotacoes = [],
    ) {
    }
}
