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

namespace App\Tests\Controller\Api;

use App\Entity\Usuario;
use App\Tests\TestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UsuariosControllerTest extends WebTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        $client = static::createClient();
        $this->em = $client->getContainer()->get(EntityManagerInterface::class);

        TestHelper::removeTestData($this->em);
        $this->em->getConnection()->executeStatement(
            "DELETE FROM usuarios_metadata WHERE usuario_id IN (SELECT id FROM usuarios WHERE login LIKE 'api_%')",
        );
        $this->em->getConnection()->executeStatement("DELETE FROM usuarios WHERE login LIKE 'api_%'");
        $this->em->clear();
    }

    public function testCreateUsuarioWithLotacoes(): void
    {
        $client = static::getClient();
        $actor = $this->actor(admin: true);
        $unidadeA = TestHelper::createUnidade($this->em, 'Unidade A');
        $unidadeB = TestHelper::createUnidade($this->em, 'Unidade B');
        $perfilA = TestHelper::createPerfil($this->em, 'Perfil A');
        $perfilB = TestHelper::createPerfil($this->em, 'Perfil B');
        $accessToken = TestHelper::generateJwtToken(static::getContainer());

        $payload = [
            'login' => 'api_usuario_criado',
            'nome' => 'Usuario',
            'sobrenome' => 'Criado',
            'email' => 'api_usuario_criado@example.com',
            'senha' => '123456',
            'lotacoes' => [
                ['unidadeId' => $unidadeA->getId(), 'perfilId' => $perfilA->getId()],
                ['unidadeId' => $unidadeB->getId(), 'perfilId' => $perfilB->getId()],
            ],
        ];

        $client->jsonRequest('POST', '/api/usuarios', parameters: $payload, server: $this->auth($accessToken));

        $this->assertResponseStatusCodeSame(201);
        $result = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($payload['login'], $result['login']);
        $this->assertCount(2, $result['lotacoes']);
        $this->assertArrayNotHasKey('senha', $result);

        $usuario = $this->em->getRepository(Usuario::class)->findOneBy(['login' => $payload['login']]);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->assertTrue($hasher->isPasswordValid($usuario, $payload['senha']));
        $this->assertTrue($usuario->isAtivo());
        $this->assertFalse($usuario->isAdmin());
        $this->assertSame($actor->getId(), TestHelper::getUser($this->em)->getId());
    }

    public function testUpdateUsuarioReconcilesLotacoes(): void
    {
        $client = static::getClient();
        $this->actor(admin: true);
        $unidadeA = TestHelper::createUnidade($this->em, 'Unidade A');
        $unidadeB = TestHelper::createUnidade($this->em, 'Unidade B');
        $perfilA = TestHelper::createPerfil($this->em, 'Perfil A');
        $perfilB = TestHelper::createPerfil($this->em, 'Perfil B');
        $usuario = $this->createUsuario('api_usuario_editar');
        TestHelper::linkUnidadeUsuario($this->em, $unidadeA, $usuario, $perfilA);
        $accessToken = TestHelper::generateJwtToken(static::getContainer());

        $client->jsonRequest(
            'PUT',
            sprintf('/api/usuarios/%d', $usuario->getId()),
            parameters: [
                'login' => 'api_usuario_editado',
                'nome' => 'Usuario',
                'sobrenome' => 'Editado',
                'email' => 'editado@example.com',
                'ativo' => true,
                'admin' => false,
                'lotacoes' => [
                    ['unidadeId' => $unidadeA->getId(), 'perfilId' => $perfilB->getId()],
                    ['unidadeId' => $unidadeB->getId(), 'perfilId' => $perfilA->getId()],
                ],
            ],
            server: $this->auth($accessToken),
        );

        $this->assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('api_usuario_editado', $result['login']);
        $this->assertCount(2, $result['lotacoes']);
        $this->assertSame($perfilB->getId(), $result['lotacoes'][0]['perfil']['id']);
    }

    public function testDeleteUsuarioUsesSoftDelete(): void
    {
        $client = static::getClient();
        $this->actor(admin: true);
        $usuario = $this->createUsuario('api_usuario_excluir');
        $id = $usuario->getId();
        $accessToken = TestHelper::generateJwtToken(static::getContainer());

        $client->request('DELETE', sprintf('/api/usuarios/%d', $id), server: $this->auth($accessToken));

        $this->assertResponseStatusCodeSame(204);
        $this->em->clear();
        $deleted = $this->em->getRepository(Usuario::class)->find($id);
        $this->assertNotNull($deleted->getDeletedAt());
        $this->assertFalse($deleted->isAtivo());
    }

    public function testGetCurrentUserAttendanceAndManageServices(): void
    {
        $client = static::getClient();
        $actor = $this->actor(admin: false);
        $unidade = TestHelper::createUnidade($this->em, 'Unidade Atendimento');
        $perfil = TestHelper::createPerfil($this->em, 'Atendente');
        TestHelper::linkUnidadeUsuario($this->em, $unidade, $actor, $perfil);
        $servico = TestHelper::createServico($this->em, 'Atendimento Geral');
        TestHelper::linkServicoUnidade($this->em, $servico, $unidade, 'AG');
        $accessToken = TestHelper::generateJwtToken(static::getContainer());
        $baseUrl = sprintf('/api/usuarios/me/unidades/%d', $unidade->getId());

        $client->request('GET', $baseUrl . '/atendimento', server: $this->auth($accessToken));

        $this->assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($unidade->getId(), $result['unidade']['id']);
        $this->assertSame($servico->getId(), $result['servicosDisponiveis'][0]['id']);
        $this->assertSame([], $result['servicos']);

        $serviceUrl = sprintf('%s/servicos/%d', $baseUrl, $servico->getId());
        $client->jsonRequest('PUT', $serviceUrl, parameters: ['peso' => 3], server: $this->auth($accessToken));
        $this->assertResponseIsSuccessful();
        $result = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(3, $result['peso']);

        $client->request('GET', $baseUrl . '/atendimento', server: $this->auth($accessToken));
        $result = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($servico->getId(), $result['servicos'][0]['id']);
        $this->assertSame(3, $result['servicos'][0]['peso']);

        $client->request('DELETE', $serviceUrl, server: $this->auth($accessToken));
        $this->assertResponseStatusCodeSame(204);
    }

    public function testCurrentUserCannotAccessUnitWithoutLotacao(): void
    {
        $client = static::getClient();
        $this->actor(admin: false);
        $unidade = TestHelper::createUnidade($this->em, 'Sem Lotacao');
        $accessToken = TestHelper::generateJwtToken(static::getContainer());

        $client->request(
            'GET',
            sprintf('/api/usuarios/me/unidades/%d/atendimento', $unidade->getId()),
            server: $this->auth($accessToken),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    private function actor(bool $admin): Usuario
    {
        /** @var Usuario $actor */
        $actor = TestHelper::getUser($this->em);
        $actor->setAdmin($admin);
        $this->em->flush();

        return $actor;
    }

    private function createUsuario(string $login): Usuario
    {
        $usuario = (new Usuario())
            ->setLogin($login)
            ->setNome('Usuario')
            ->setSobrenome('API')
            ->setEmail($login . '@example.com')
            ->setSenha('test')
            ->setAtivo(true)
            ->setAdmin(false);
        $this->em->persist($usuario);
        $this->em->flush();

        return $usuario;
    }

    /** @return array<string,string> */
    private function auth(string $accessToken): array
    {
        return ['HTTP_AUTHORIZATION' => sprintf('Bearer %s', $accessToken)];
    }
}
