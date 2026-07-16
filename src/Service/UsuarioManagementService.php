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

namespace App\Service;

use App\Dto\AtualizarUsuario;
use App\Dto\CriarUsuario;
use App\Dto\UsuarioLotacao;
use App\Entity\Lotacao;
use App\Entity\Perfil;
use App\Entity\Unidade;
use App\Entity\Usuario;
use App\Repository\UnidadeRepository;
use App\Repository\UsuarioRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UsuarioManagementService
{
    private const ROLE_USUARIOS = 'ROLE_NOVOSGA_USERS';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UsuarioRepository $usuarioRepository,
        private readonly UnidadeRepository $unidadeRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function assertCanManage(Usuario $actor): void
    {
        if (!$actor->isAdmin() && !in_array(self::ROLE_USUARIOS, $actor->getRoles(), true)) {
            throw new AccessDeniedHttpException('Usuário sem permissão para gerenciar usuários.');
        }
    }

    public function getManagedUser(Usuario $actor, int $id): Usuario
    {
        $this->assertCanManage($actor);

        $usuario = $this->usuarioRepository->findNotDeleted($id);
        if (!$usuario) {
            throw new NotFoundHttpException('Usuário não encontrado.');
        }

        $this->assertCanManageTarget($actor, $usuario);

        return $usuario;
    }

    public function create(Usuario $actor, CriarUsuario $data): Usuario
    {
        $this->assertCanManage($actor);

        if (!$actor->isAdmin() && $data->admin) {
            throw new AccessDeniedHttpException('Somente administradores podem criar outro administrador.');
        }

        try {
            return $this->em->wrapInTransaction(function () use ($actor, $data): Usuario {
                $usuario = (new Usuario())
                    ->setLogin($data->login)
                    ->setNome($data->nome)
                    ->setSobrenome($data->sobrenome)
                    ->setEmail($this->normalizeEmail($data->email))
                    ->setAdmin($actor->isAdmin() && $data->admin)
                    ->setAtivo(true);

                $usuario->setSenha($this->passwordHasher->hashPassword($usuario, (string) $data->senha));
                $this->reconcileLotacoes($actor, $usuario, $data->lotacoes);
                $this->validate($usuario);

                $this->em->persist($usuario);
                $this->em->flush();

                return $usuario;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException('Login ou e-mail já cadastrado.', $exception);
        }
    }

    public function update(Usuario $actor, Usuario $usuario, AtualizarUsuario $data): Usuario
    {
        $this->assertCanManageTarget($actor, $usuario);

        if (!$actor->isAdmin() && $data->admin) {
            throw new AccessDeniedHttpException('Somente administradores podem alterar o nível administrativo.');
        }

        try {
            return $this->em->wrapInTransaction(function () use ($actor, $usuario, $data): Usuario {
                $usuario
                    ->setLogin($data->login)
                    ->setNome($data->nome)
                    ->setSobrenome($data->sobrenome)
                    ->setEmail($this->normalizeEmail($data->email))
                    ->setAtivo((bool) $data->ativo);

                if ($actor->isAdmin()) {
                    $usuario->setAdmin($data->admin);
                }

                $this->reconcileLotacoes($actor, $usuario, $data->lotacoes);
                $this->validate($usuario);

                $this->em->persist($usuario);
                $this->em->flush();

                return $usuario;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException('Login ou e-mail já cadastrado.', $exception);
        }
    }

    public function changePassword(Usuario $actor, Usuario $usuario, string $password): void
    {
        $this->assertCanManageTarget($actor, $usuario);

        $usuario->setSenha($this->passwordHasher->hashPassword($usuario, $password));
        $this->em->persist($usuario);
        $this->em->flush();
    }

    public function delete(Usuario $actor, Usuario $usuario): void
    {
        $this->assertCanManage($actor);

        if (!$actor->isAdmin()) {
            throw new AccessDeniedHttpException('Somente administradores podem excluir usuários.');
        }
        if ($actor->getId() === $usuario->getId()) {
            throw new ConflictHttpException('Não é possível excluir o próprio usuário.');
        }
        if ($usuario->isAdmin()) {
            throw new ConflictHttpException('Não é possível excluir um usuário administrador.');
        }

        $this->em->wrapInTransaction(function () use ($usuario): void {
            $usuario->setAtivo(false);
            $this->em->remove($usuario);
            $this->em->flush();
            $this->revokeOauthTokens((string) $usuario->getLogin());
        });
    }

    /**
     * @param array<UsuarioLotacao|array<string,mixed>> $requestedLotacoes
     */
    private function reconcileLotacoes(Usuario $actor, Usuario $usuario, array $requestedLotacoes): void
    {
        $allowedUnitIds = $this->allowedUnitIds($actor);
        $requested = [];

        foreach ($requestedLotacoes as $item) {
            $lotacao = $this->normalizeLotacao($item);
            $unidadeId = (int) $lotacao->unidadeId;

            if (isset($requested[$unidadeId])) {
                throw new UnprocessableEntityHttpException('Só pode existir uma lotação por unidade.');
            }
            if (!$actor->isAdmin() && !in_array($unidadeId, $allowedUnitIds, true)) {
                throw new AccessDeniedHttpException('Sem permissão para alterar a lotação da unidade informada.');
            }

            $unidade = $this->em->getRepository(Unidade::class)->find($unidadeId);
            $perfil = $this->em->getRepository(Perfil::class)->find((int) $lotacao->perfilId);
            if (!$unidade || $unidade->getDeletedAt() !== null) {
                throw new UnprocessableEntityHttpException('Unidade inválida.');
            }
            if (!$perfil) {
                throw new UnprocessableEntityHttpException('Perfil inválido.');
            }

            $requested[$unidadeId] = [$unidade, $perfil];
        }

        foreach ($usuario->getLotacoes()->toArray() as $existing) {
            if (!$existing instanceof Lotacao) {
                continue;
            }

            $unidadeId = (int) $existing->getUnidade()?->getId();

            if (!$actor->isAdmin() && !in_array($unidadeId, $allowedUnitIds, true)) {
                continue;
            }

            if (isset($requested[$unidadeId])) {
                [, $perfil] = $requested[$unidadeId];
                $existing->setPerfil($perfil);
                unset($requested[$unidadeId]);
            } else {
                $usuario->removeLotacoe($existing);
            }
        }

        foreach ($requested as [$unidade, $perfil]) {
            $usuario->addLotacoe(
                (new Lotacao())
                    ->setUnidade($unidade)
                    ->setPerfil($perfil),
            );
        }

        if (!$usuario->isAdmin() && $usuario->getLotacoes()->isEmpty()) {
            throw new UnprocessableEntityHttpException('Usuário não administrador precisa de ao menos uma lotação.');
        }
    }

    private function assertCanManageTarget(Usuario $actor, Usuario $target): void
    {
        $this->assertCanManage($actor);

        if ($actor->isAdmin()) {
            return;
        }
        if ($target->isAdmin()) {
            throw new AccessDeniedHttpException('Sem permissão para alterar um usuário administrador.');
        }

        $allowed = $this->allowedUnitIds($actor);
        foreach ($target->getLotacoes() as $lotacao) {
            if (in_array($lotacao->getUnidade()?->getId(), $allowed, true)) {
                return;
            }
        }

        throw new AccessDeniedHttpException('Usuário fora das unidades permitidas.');
    }

    /** @return int[] */
    public function allowedUnitIds(Usuario $actor): array
    {
        return array_map(
            static fn ($unidade): int => (int) $unidade->getId(),
            $this->unidadeRepository->findByUsuario($actor),
        );
    }

    /** @param UsuarioLotacao|array<string,mixed> $item */
    private function normalizeLotacao(UsuarioLotacao|array $item): UsuarioLotacao
    {
        if ($item instanceof UsuarioLotacao) {
            return $item;
        }

        $lotacao = new UsuarioLotacao(
            unidadeId: isset($item['unidadeId']) ? (int) $item['unidadeId'] : null,
            perfilId: isset($item['perfilId']) ? (int) $item['perfilId'] : null,
        );
        $violations = $this->validator->validate($lotacao);
        if (count($violations) > 0) {
            throw new UnprocessableEntityHttpException(
                'Lotação inválida.',
                new ValidationFailedException($lotacao, $violations),
            );
        }

        return $lotacao;
    }

    private function validate(Usuario $usuario): void
    {
        $violations = $this->validator->validate($usuario);
        foreach ($usuario->getLotacoes() as $lotacao) {
            $violations->addAll($this->validator->validate($lotacao));
        }

        if (count($violations) > 0) {
            throw new UnprocessableEntityHttpException(
                'Dados do usuário inválidos.',
                new ValidationFailedException($usuario, $violations),
            );
        }
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = trim((string) $email);

        return $email === '' ? null : $email;
    }

    private function revokeOauthTokens(string $login): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'UPDATE oauth2_refresh_token SET revoked = TRUE WHERE access_token IN '
            . '(SELECT identifier FROM oauth2_access_token WHERE user_identifier = :login)',
            ['login' => $login],
        );
        $connection->executeStatement(
            'UPDATE oauth2_access_token SET revoked = TRUE WHERE user_identifier = :login',
            ['login' => $login],
        );
        $connection->executeStatement(
            'UPDATE oauth2_authorization_code SET revoked = TRUE WHERE user_identifier = :login',
            ['login' => $login],
        );
    }
}
