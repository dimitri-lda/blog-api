<?php

namespace App\Presentation;

use App\Application\ApiView;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/profile')]
final class ProfileController extends ApiController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly UserPasswordHasherInterface $hasher)
    {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->data(ApiView::user($this->user()));
    }

    #[Route('', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $data = $this->body($request);
        $errors = $this->missing($data, ['name', 'email']);
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) $errors['email'] = ['Enter a valid email.'];
        $other = $this->em->getRepository(User::class)->findOneBy(['email' => strtolower((string)($data['email'] ?? ''))]);
        if ($other && $other !== $this->user()) $errors['email'] = ['This email is already used.'];
        if ($errors) return $this->problem('Validation failed.', $errors);
        $this->user()->setName(trim($data['name']));
        if ($this->user()->getEmail() !== strtolower($data['email'])) $this->user()->setEmail($data['email']);
        $this->em->flush();
        return $this->data(ApiView::user($this->user()));
    }

    #[Route('/password', methods: ['PUT'])]
    public function password(Request $request): JsonResponse
    {
        $data = $this->body($request);
        if (!$this->hasher->isPasswordValid($this->user(), (string)($data['current_password'] ?? ''))) return $this->problem('Validation failed.', ['current_password' => ['The password is incorrect.']]);
        if (strlen((string)($data['password'] ?? '')) < 8) return $this->problem('Validation failed.', ['password' => ['Use at least 8 characters.']]);
        $this->user()->setPassword($this->hasher->hashPassword($this->user(), $data['password']));
        $this->em->flush();
        return $this->data(['message' => 'Password updated.']);
    }

    #[Route('', methods: ['DELETE'])]
    public function delete(Request $request): Response
    {
        if (!$this->hasher->isPasswordValid($this->user(), (string)($this->body($request)['password'] ?? ''))) return $this->problem('Validation failed.', ['password' => ['The password is incorrect.']]);
        $this->em->remove($this->user());
        $this->em->flush();
        return new Response(status: 204);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
    }
}
