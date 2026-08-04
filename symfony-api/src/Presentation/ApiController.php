<?php

namespace App\Presentation;

use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};

abstract class ApiController extends AbstractController
{
    protected function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (JsonException) {
            return [];
        }
    }

    protected function data(mixed $data, int $status = 200): JsonResponse
    {
        return $this->json(['data' => $data], $status);
    }

    protected function problem(string $message, array $errors = [], int $status = 422): JsonResponse
    {
        return $this->json(['message' => $message, 'errors' => $errors], $status);
    }

    protected function missing(array $data, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) if (!isset($data[$field]) || trim((string)$data[$field]) === '') $errors[$field] = ['This value should not be blank.'];
        return $errors;
    }
}
