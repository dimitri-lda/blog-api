<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Commerce\CountryCatalog;
use App\Application\Security\ProfileException;
use App\Infrastructure\Persistence\Doctrine\Users\DbalProfileService;
use App\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/api/v1/profile')]
final readonly class ProfileController
{
    public function __construct(
        private DbalProfileService $profiles,
        private CountryCatalog $countries,
        private Security $security,
    ) {
    }

    #[Route('', name: 'api.v1.profile', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => $this->profiles->profile($this->user())]);
    }

    #[Route('', name: 'api.v1.profile.update', methods: ['PATCH'])]
    public function update(
        #[MapRequestPayload] UpdateProfilePayload $payload,
    ): JsonResponse {
        try {
            $this->profiles->update($this->user(), $payload->name, $payload->email);
        } catch (ProfileException $exception) {
            return $this->error($exception);
        }

        return $this->show();
    }

    #[Route('/address', name: 'api.v1.profile.address', methods: ['PUT'])]
    public function address(
        #[MapRequestPayload] SavedAddressPayload $payload,
    ): JsonResponse {
        if (!$this->countries->supports($payload->country)) {
            return $this->error(new ProfileException('country', 'Choose a supported country.'));
        }
        $this->profiles->saveAddress($this->user(), $payload->toArray());

        return $this->show();
    }

    #[Route('/password', name: 'api.v1.profile.password', methods: ['PUT'])]
    public function password(
        #[MapRequestPayload] ChangePasswordPayload $payload,
    ): JsonResponse {
        if (!hash_equals($payload->password, $payload->password_confirmation)) {
            return $this->error(new ProfileException(
                'password',
                'The password confirmation does not match.',
            ));
        }

        try {
            $this->profiles->changePassword(
                $this->user(),
                $payload->current_password,
                $payload->password,
            );
        } catch (ProfileException $exception) {
            return $this->error($exception);
        }

        return new JsonResponse(['data' => ['password_updated' => true]]);
    }

    #[Route('', name: 'api.v1.profile.delete', methods: ['DELETE'])]
    public function delete(
        Request $request,
        #[MapRequestPayload] DeleteProfilePayload $payload,
    ): JsonResponse {
        try {
            $this->profiles->delete($this->user(), $payload->password);
        } catch (ProfileException $exception) {
            return $this->error($exception);
        }
        $request->getSession()->invalidate();

        return new JsonResponse(null, 204);
    }

    private function user(): SecurityUser
    {
        $user = $this->security->getUser();
        if (!$user instanceof SecurityUser) {
            throw new \LogicException('Authenticated user is unavailable.');
        }

        return $user;
    }

    private function error(ProfileException $exception): JsonResponse
    {
        return new JsonResponse([
            'type' => 'https://httpstatuses.com/422',
            'title' => 'Validation failed.',
            'status' => 422,
            'errors' => [
                $exception->field => [$exception->getMessage()],
            ],
        ], 422);
    }
}
