<?php

namespace App\Application;

use DateTimeImmutable;
use DomainException;
use App\Entity\{RefreshToken, User};
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final readonly class TokenService
{
    public function __construct(private EntityManagerInterface $em, private JWTTokenManagerInterface $jwt, #[Autowire('%app.refresh_token_ttl%')] private int $ttl)
    {
    }

    /** @return array{access_token:string,refresh_token:string,expires_in:int,user:User} */
    public function issue(User $user, ?string $family = null): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $family ??= Uuid::v4()->toRfc4122();
        $this->em->persist(new RefreshToken($user, hash('sha256', $raw), $family, new DateTimeImmutable("+{$this->ttl} seconds")));
        $this->em->flush();
        return ['access_token' => $this->jwt->create($user), 'refresh_token' => $raw, 'expires_in' => 900, 'user' => $user];
    }

    public function rotate(string $raw): array
    {
        $token = $this->em->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => hash('sha256', $raw)]);
        if (!$token) throw new DomainException('Invalid refresh token.');
        if (!$token->isValid()) {
            $this->revokeFamily($token->getFamily());
            throw new DomainException('Refresh token reuse detected.');
        }
        $token->revoke();
        return $this->issue($token->getUser(), $token->getFamily());
    }

    public function revoke(string $raw): void
    {
        $token = $this->em->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => hash('sha256', $raw)]);
        if ($token) {
            $token->revoke();
            $this->em->flush();
        }
    }

    private function revokeFamily(string $family): void
    {
        foreach ($this->em->getRepository(RefreshToken::class)->findBy(['family' => $family]) as $token) $token->revoke();
        $this->em->flush();
    }
}
