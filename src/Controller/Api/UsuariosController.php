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

namespace App\Controller\Api;

use App\Dto\AlterarSenhaUsuario;
use App\Dto\AtualizarUsuario;
use App\Dto\ConfigurarAtendimentoUsuario;
use App\Dto\ConfigurarServicoUsuario;
use App\Dto\CriarUsuario;
use App\Entity\Usuario;
use App\Repository\UsuarioRepository;
use App\Service\UsuarioAtendimentoService;
use App\Service\UsuarioManagementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/usuarios', name: 'api_usuarios_')]
class UsuariosController extends ApiControllerBase
{
    private const SEARCHABLE_FIELDS = ['id', 'login', 'nome', 'email'];
    private const SORTABLE_FIELDS = ['id', 'login', 'nome', 'email', 'ativo', 'createdAt', 'updatedAt'];

    public function __construct(
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        private readonly UsuarioRepository $usuarioRepository,
        private readonly UsuarioManagementService $managementService,
        private readonly UsuarioAtendimentoService $atendimentoService,
    ) {
        parent::__construct($em, $translator);
    }

    #[Route('', name: 'find', methods: ['GET'])]
    public function find(Request $request): Response
    {
        $actor = $this->currentUser();
        $this->managementService->assertCanManage($actor);

        try {
            $queries = $request->query->all('q');
        } catch (BadRequestException) {
            $queries = [$request->query->get('q', '')];
        }

        $criteria = [];
        foreach ($queries as $query) {
            $parts = explode(':', (string) $query, 2);
            if (count($parts) === 2 && in_array($parts[0], self::SEARCHABLE_FIELDS, true)) {
                $criteria[$parts[0]] = $parts[1];
            }
        }

        $sort = (string) $request->query->get('sort', '');
        $order = strtolower((string) $request->query->get('order', 'asc'));
        $orderBy = [];
        if (in_array($sort, self::SORTABLE_FIELDS, true)) {
            $orderBy[$sort] = in_array($order, ['asc', 'desc'], true) ? $order : 'asc';
        }

        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));
        $offset = max(0, (int) $request->query->get('offset', 0));
        $allowedUnitIds = $this->managementService->allowedUnitIds($actor);
        $usuarios = $this->usuarioRepository->findAccessible(
            $actor,
            $criteria,
            $orderBy,
            $limit,
            $offset,
            $allowedUnitIds,
        );

        return $this->json(array_map(
            fn (Usuario $usuario): array => $this->serializeUsuario($usuario, $actor, $allowedUnitIds),
            $usuarios,
        ));
    }

    #[Route('', name: 'create', methods: ['POST'], format: 'json')]
    public function create(#[MapRequestPayload] CriarUsuario $data): Response
    {
        $actor = $this->currentUser();
        $usuario = $this->managementService->create($actor, $data);

        return $this->json(
            $this->serializeUsuario($usuario, $actor),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_usuarios_get', ['id' => $usuario->getId()])],
        );
    }

    #[Route('/me/unidades/{unidadeId}/atendimento', name: 'my_attendance', methods: ['GET'])]
    public function myAttendance(int $unidadeId): Response
    {
        return $this->json(
            $this->atendimentoService->getConfiguration($this->currentUser(), $unidadeId),
        );
    }

    #[Route(
        '/me/unidades/{unidadeId}/atendimento',
        name: 'put_my_attendance',
        methods: ['PUT'],
        format: 'json',
    )]
    public function putMyAttendance(
        #[MapRequestPayload] ConfigurarAtendimentoUsuario $data,
        int $unidadeId,
    ): Response {
        return $this->json($this->atendimentoService->putConfiguration(
            $this->currentUser(),
            $unidadeId,
            (int) $data->localId,
            (int) $data->numeroLocal,
        ));
    }

    #[Route(
        '/me/unidades/{unidadeId}/servicos/{servicoId}',
        name: 'put_my_service',
        methods: ['PUT'],
        format: 'json',
    )]
    public function putMyService(
        #[MapRequestPayload] ConfigurarServicoUsuario $data,
        int $unidadeId,
        int $servicoId,
    ): Response {
        $servico = $this->atendimentoService->putService(
            $this->currentUser(),
            $unidadeId,
            $servicoId,
            (int) $data->peso,
        );

        return $this->json($servico);
    }

    #[Route(
        '/me/unidades/{unidadeId}/servicos/{servicoId}',
        name: 'remove_my_service',
        methods: ['DELETE'],
    )]
    public function removeMyService(int $unidadeId, int $servicoId): Response
    {
        $this->atendimentoService->removeService($this->currentUser(), $unidadeId, $servicoId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'get', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function get(int $id): Response
    {
        $actor = $this->currentUser();
        $usuario = $this->managementService->getManagedUser($actor, $id);

        return $this->json($this->serializeUsuario($usuario, $actor));
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\\d+'], methods: ['PUT'], format: 'json')]
    public function update(#[MapRequestPayload] AtualizarUsuario $data, int $id): Response
    {
        $actor = $this->currentUser();
        $usuario = $this->managementService->getManagedUser($actor, $id);
        $this->managementService->update($actor, $usuario, $data);

        return $this->json($this->serializeUsuario($usuario, $actor));
    }

    #[Route(
        '/{id}/senha',
        name: 'change_password',
        requirements: ['id' => '\\d+'],
        methods: ['PUT'],
        format: 'json',
    )]
    public function changePassword(#[MapRequestPayload] AlterarSenhaUsuario $data, int $id): Response
    {
        $actor = $this->currentUser();
        $usuario = $this->managementService->getManagedUser($actor, $id);
        $this->managementService->changePassword($actor, $usuario, (string) $data->senha);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $actor = $this->currentUser();
        $usuario = $this->managementService->getManagedUser($actor, $id);
        $this->managementService->delete($actor, $usuario);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function currentUser(): Usuario
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof Usuario) {
            throw new AccessDeniedHttpException('Usuário autenticado inválido.');
        }

        return $usuario;
    }

    /**
     * @param int[]|null $allowedUnitIds
     * @return array<string,mixed>
     */
    private function serializeUsuario(
        Usuario $usuario,
        Usuario $actor,
        ?array $allowedUnitIds = null,
    ): array {
        $allowedUnitIds ??= $this->managementService->allowedUnitIds($actor);
        $lotacoes = [];

        foreach ($usuario->getLotacoes() as $lotacao) {
            $unidade = $lotacao->getUnidade();
            $perfil = $lotacao->getPerfil();
            if (!$actor->isAdmin() && !in_array($unidade?->getId(), $allowedUnitIds, true)) {
                continue;
            }

            $lotacoes[] = [
                'id' => $lotacao->getId(),
                'unidade' => [
                    'id' => $unidade?->getId(),
                    'nome' => $unidade?->getNome(),
                ],
                'perfil' => [
                    'id' => $perfil?->getId(),
                    'nome' => $perfil?->getNome(),
                ],
            ];
        }

        return [
            'id' => $usuario->getId(),
            'login' => $usuario->getLogin(),
            'nome' => $usuario->getNome(),
            'sobrenome' => $usuario->getSobrenome(),
            'email' => $usuario->getEmail(),
            'ativo' => $usuario->isAtivo(),
            'admin' => $usuario->isAdmin(),
            'lotacoes' => $lotacoes,
            'createdAt' => $usuario->getCreatedAt()?->format('Y-m-d\TH:i:s'),
            'updatedAt' => $usuario->getUpdatedAt()?->format('Y-m-d\TH:i:s'),
        ];
    }
}
