<?php

namespace App\Presentation;

use App\Application\{ApiView, TokenService};
use App\Entity\{AccountToken, User};
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Cookie, JsonResponse, Request, Response};
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth')]
final class AuthController extends ApiController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher, private readonly TokenService $tokens, #[Autowire('%app.cookie_secure%')] private readonly bool $secureCookie, #[Autowire('%app.url%')] private readonly string $appUrl)
    {
    }

    #[Route('/register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $errors = $this->missing($data, ['name', 'email', 'password']);
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors['email'] = ['Enter a valid email.'];
        if (strlen((string)($data['password'] ?? '')) < 8) $errors['password'] = ['Use at least 8 characters.'];
        if (!($data['accepted_terms'] ?? false)) $errors['accepted_terms'] = ['You must accept the terms.'];
        if ($errors) return $this->problem('Validation failed.', $errors);
        if ($this->em->getRepository(User::class)->findOneBy(['email' => strtolower($data['email'])])) return $this->problem('Validation failed.', ['email' => ['This email is already registered.']]);
        $user = new User(trim($data['name']), $data['email']);
        $user->acceptTerms();
        $user->setPassword($this->hasher->hashPassword($user, $data['password']));
        $this->em->persist($user);
        $this->em->flush();
        return $this->authResponse($this->tokens->issue($user), $user, 201);
    }

    #[Route('/login', methods: ['POST'])]
    public function login(Request $request, #[Autowire(service: 'limiter.login')] RateLimiterFactory $limiter): JsonResponse
    {
        $data = $this->body($request);
        $key = strtolower((string)($data['email'] ?? '')) . '|' . $request->getClientIp();
        if (!$limiter->create($key)->consume()->isAccepted()) return $this->problem('Too many login attempts.', [], 429);
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower((string)($data['email'] ?? ''))]);
        if (!$user || !$this->hasher->isPasswordValid($user, (string)($data['password'] ?? ''))) return $this->problem('Invalid credentials.', ['email' => ['The provided credentials are incorrect.']], 401);
        return $this->authResponse($this->tokens->issue($user), $user);
    }

    #[Route('/refresh', methods: ['POST'])]
    public function refresh(Request $request, #[Autowire(service: 'limiter.refresh')] RateLimiterFactory $limiter): JsonResponse
    {
        $raw = $request->cookies->get('refresh_token');
        if (!$raw) return $this->problem('Refresh token is missing.', [], 401);
        if (!$limiter->create((string)$request->getClientIp())->consume()->isAccepted()) return $this->problem('Too many requests.', [], 429);
        try {
            $issued = $this->tokens->rotate($raw);
        } catch (DomainException $e) {
            $response = $this->problem($e->getMessage(), [], 401);
            $response->headers->clearCookie('refresh_token', '/api/auth');
            return $response;
        }
        return $this->authResponse($issued, $issued['user']);
    }

    #[Route('/logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $raw = $request->cookies->get('refresh_token');
        if ($raw) $this->tokens->revoke($raw);
        $response = new Response(status: 204);
        $response->headers->clearCookie('refresh_token', '/api/auth');
        return $response;
    }

    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        return $this->getUser() instanceof User ? $this->data(ApiView::user($this->getUser())) : $this->problem('Authentication required.', [], 401);
    }

    #[Route('/forgot-password', methods: ['POST'])]
    public function forgot(Request $request, MailerInterface $mailer, #[Autowire(service: 'limiter.password_reset')] RateLimiterFactory $limiter): JsonResponse
    {
        if (!$limiter->create((string)$request->getClientIp())->consume()->isAccepted()) return $this->problem('Too many requests.', [], 429);
        $email = strtolower((string)($this->body($request)['email'] ?? ''));
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user) {
            $raw = $this->accountToken($user, 'password_reset', 3600);
            $mailer->send((new Email())->from('hello@daosport.test')->to($user->getEmail())->subject('Reset your daoSport password')->text("Reset your password: {$this->appUrl}/reset-password?token={$raw}"));
        }
        return $this->data(['message' => 'If the account exists, a reset link has been sent.']);
    }

    #[Route('/reset-password', methods: ['POST'])]
    public function reset(Request $request, #[Autowire(service: 'limiter.password_reset')] RateLimiterFactory $limiter): JsonResponse
    {
        if (!$limiter->create((string)$request->getClientIp())->consume()->isAccepted()) return $this->problem('Too many requests.', [], 429);
        $data = $this->body($request);
        $token = $this->findAccountToken((string)($data['token'] ?? ''), 'password_reset');
        if (!$token) return $this->problem('Invalid or expired reset token.', ['token' => ['Invalid or expired token.']]);
        if (strlen((string)($data['password'] ?? '')) < 8) return $this->problem('Validation failed.', ['password' => ['Use at least 8 characters.']]);
        $token->getUser()->setPassword($this->hasher->hashPassword($token->getUser(), $data['password']));
        $token->use();
        $this->em->flush();
        return $this->data(['message' => 'Password updated.']);
    }

    #[Route('/verification/send', methods: ['POST'])]
    public function sendVerification(MailerInterface $mailer, #[Autowire(service: 'limiter.verification')] RateLimiterFactory $limiter): JsonResponse
    {
        if (!$this->getUser() instanceof User) return $this->problem('Authentication required.', [], 401);
        if (!$limiter->create((string)$this->getUser()->getId())->consume()->isAccepted()) return $this->problem('Too many requests.', [], 429);
        $raw = $this->accountToken($this->getUser(), 'email_verify', 86400);
        $mailer->send((new Email())->from('hello@daosport.test')->to($this->getUser()->getEmail())->subject('Verify your daoSport email')->text("Verify your email: {$this->appUrl}/verify-email?token={$raw}"));
        return $this->data(['message' => 'Verification email sent.']);
    }

    #[Route('/verify-email', methods: ['POST'])]
    public function verify(Request $request, #[Autowire(service: 'limiter.verification')] RateLimiterFactory $limiter): JsonResponse
    {
        if (!$limiter->create((string)$request->getClientIp())->consume()->isAccepted()) return $this->problem('Too many requests.', [], 429);
        $token = $this->findAccountToken((string)($this->body($request)['token'] ?? ''), 'email_verify');
        if (!$token) return $this->problem('Invalid or expired verification token.', [], 422);
        $token->getUser()->verifyEmail();
        $token->use();
        $this->em->flush();
        return $this->data(['message' => 'Email verified.']);
    }

    #[Route('/confirm-password', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        if (!$this->getUser() instanceof User) return $this->problem('Authentication required.', [], 401);
        return $this->hasher->isPasswordValid($this->getUser(), (string)($this->body($request)['password'] ?? '')) ? $this->data(['confirmed' => true]) : $this->problem('The password is incorrect.', ['password' => ['The password is incorrect.']]);
    }

    private function authResponse(array $issued, User $user, int $status = 200): JsonResponse
    {
        $response = $this->data(['access_token' => $issued['access_token'], 'expires_in' => $issued['expires_in'], 'user' => ApiView::user($user)], $status);
        $response->headers->setCookie(Cookie::create('refresh_token', $issued['refresh_token'], new DateTimeImmutable('+30 days'), '/api/auth', null, $this->secureCookie, true, false, Cookie::SAMESITE_LAX));
        return $response;
    }

    private function accountToken(User $user, string $type, int $ttl): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->em->persist(new AccountToken($user, hash('sha256', $raw), $type, new DateTimeImmutable("+{$ttl} seconds")));
        $this->em->flush();
        return $raw;
    }

    private function findAccountToken(string $raw, string $type): ?AccountToken
    {
        $token = $this->em->getRepository(AccountToken::class)->findOneBy(['tokenHash' => hash('sha256', $raw)]);
        return $token?->isUsable($type) ? $token : null;
    }
}
