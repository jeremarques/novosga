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

use App\Entity\Unidade;
use App\Entity\Usuario;
use App\Repository\LocalRepository;
use App\Repository\LotacaoRepository;
use App\Repository\ServicoUnidadeRepository;
use App\Repository\ServicoUsuarioRepository;
use App\Repository\UnidadeRepository;
use Novosga\Entity\ServicoUnidadeInterface;
use Novosga\Entity\ServicoUsuarioInterface;
use Novosga\Service\FilaServiceInterface;
use Novosga\Service\UsuarioServiceInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class UsuarioAtendimentoService
{
    public function __construct(
        private readonly UnidadeRepository $unidadeRepository,
        private readonly LotacaoRepository $lotacaoRepository,
        private readonly LocalRepository $localRepository,
        private readonly ServicoUnidadeRepository $servicoUnidadeRepository,
        private readonly ServicoUsuarioRepository $servicoUsuarioRepository,
        private readonly UsuarioService $usuarioService,
        private readonly ServicoService $servicoService,
        private readonly ApplicationSettingsService $settingsService,
    ) {
    }

    /** @return array<string,mixed> */
    public function getConfiguration(Usuario $usuario, int $unidadeId): array
    {
        $unidade = $this->getAccessibleUnit($usuario, $unidadeId);
        $servicos = [];

        foreach ($this->servicoUsuarioRepository->getAll($usuario, $unidade) as $servicoUsuario) {
            $servicoUnidade = $this->servicoUnidadeRepository->get(
                $unidade,
                $servicoUsuario->getServico(),
            );
            if ($servicoUnidade) {
                $servicos[] = $this->serializeServicoUsuario($servicoUsuario, $servicoUnidade);
            }
        }

        $disponiveis = [];
        foreach ($this->servicoService->servicosIndisponiveis($unidade, $usuario) as $servicoUnidade) {
            if ($servicoUnidade->getServico()?->isAtivo()) {
                $disponiveis[] = $this->serializeServicoDisponivel($servicoUnidade);
            }
        }

        $tipo = $this->usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_TIPO);
        $local = $this->usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_LOCAL);
        $numero = $this->usuarioService->meta($usuario, UsuarioServiceInterface::ATTR_ATENDIMENTO_NUM_LOCAL);
        $localEntity = $local ? $this->localRepository->find((int) $local->getValue()) : null;
        $behavior = $this->settingsService->loadUserBehaviorSettings($usuario);

        return [
            'unidade' => [
                'id' => $unidade->getId(),
                'nome' => $unidade->getNome(),
            ],
            'tipoAtendimento' => $tipo?->getValue() ?? FilaServiceInterface::TIPO_TODOS,
            'local' => $localEntity ? [
                'id' => $localEntity->getId(),
                'nome' => $localEntity->getNome(),
            ] : null,
            'numeroLocal' => $numero ? (int) $numero->getValue() : null,
            'behavior' => [
                'callTicketByService' => $behavior->callTicketByService,
                'callTicketOutOfOrder' => $behavior->callTicketOutOfOrder,
                'changeTicketType' => $behavior->changeTicketType,
            ],
            'servicos' => $servicos,
            'servicosDisponiveis' => $disponiveis,
        ];
    }

    /** @return array<string,mixed> */
    public function putService(Usuario $usuario, int $unidadeId, int $servicoId, int $peso): array
    {
        $unidade = $this->getAccessibleUnit($usuario, $unidadeId);
        $servicoUnidade = $this->servicoUnidadeRepository->get($unidade, $servicoId);

        if (
            !$servicoUnidade
            || !$servicoUnidade->isAtivo()
            || !$servicoUnidade->getServico()?->isAtivo()
        ) {
            throw new UnprocessableEntityHttpException('Serviço indisponível na unidade informada.');
        }

        $servico = $servicoUnidade->getServico();
        $servicoUsuario = $this->servicoUsuarioRepository->findOneBy([
            'usuario' => $usuario,
            'unidade' => $unidade,
            'servico' => $servico,
        ]);

        if ($servicoUsuario) {
            $servicoUsuario = $this->usuarioService->updateServicoUsuario(
                $usuario,
                $servico,
                $unidade,
                $peso,
            );
        } else {
            $servicoUsuario = $this->usuarioService->addServicoUsuario(
                $usuario,
                $servico,
                $unidade,
                $peso,
            );
        }

        return $this->serializeServicoUsuario($servicoUsuario, $servicoUnidade);
    }

    public function removeService(Usuario $usuario, int $unidadeId, int $servicoId): void
    {
        $unidade = $this->getAccessibleUnit($usuario, $unidadeId);
        $servico = $this->servicoService->getById($servicoId);

        if (!$servico) {
            return;
        }

        $this->usuarioService->removeServicoUsuario($usuario, $servico, $unidade);
    }

    private function getAccessibleUnit(Usuario $usuario, int $unidadeId): Unidade
    {
        $unidade = $this->unidadeRepository->find($unidadeId);
        if (
            !$unidade instanceof Unidade
            || $unidade->getDeletedAt() !== null
            || !$unidade->isAtivo()
        ) {
            throw new NotFoundHttpException('Unidade não encontrada.');
        }

        if (!$usuario->isAdmin() && !$this->lotacaoRepository->getLotacao($usuario, $unidade)) {
            throw new AccessDeniedHttpException('Usuário não possui lotação na unidade informada.');
        }

        return $unidade;
    }

    /** @return array<string,mixed> */
    private function serializeServicoUsuario(
        ServicoUsuarioInterface $servicoUsuario,
        ServicoUnidadeInterface $servicoUnidade,
    ): array {
        $servico = $servicoUsuario->getServico();

        return [
            'id' => $servico?->getId(),
            'nome' => $servico?->getNome(),
            'sigla' => $servicoUnidade->getSigla(),
            'peso' => $servicoUsuario->getPeso(),
            'ativo' => $servico?->isAtivo() && $servicoUnidade->isAtivo(),
        ];
    }

    /** @return array<string,mixed> */
    private function serializeServicoDisponivel(ServicoUnidadeInterface $servicoUnidade): array
    {
        $servico = $servicoUnidade->getServico();

        return [
            'id' => $servico?->getId(),
            'nome' => $servico?->getNome(),
            'sigla' => $servicoUnidade->getSigla(),
            'peso' => 1,
            'ativo' => $servico?->isAtivo() && $servicoUnidade->isAtivo(),
        ];
    }
}
