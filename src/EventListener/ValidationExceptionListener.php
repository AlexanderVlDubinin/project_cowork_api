<?php

namespace App\EventListener;

use App\Enum\BookingStatus;
use App\Enum\ResourceType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(event: 'kernel.exception', priority: 10)]
class ValidationExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Checking if this is an HTTP error
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $previous = $exception->getPrevious();

        if ($previous instanceof ValidationFailedException) {
            $errors = [];

            foreach ($previous->getViolations() as $violation) {
                $property = $violation->getPropertyPath();
                $enumMap = [
                    'type' => ResourceType::class,
                    'status' => BookingStatus::class,
                ];
                if (isset($enumMap[$property])) {
                    /** @var class-string<\BackedEnum> $enumClass */
                    $enumClass = $enumMap[$property];
                    $allowedValues = array_column($enumClass::cases(), 'value');
                    $allowedString = implode(', ', $allowedValues);
                    $errors[$property] = 'Invalid resource type. Available options: ' . $allowedString;
                } else {
                    $errors[$property] = $violation->getMessage();
                }
            }

            $response = new JsonResponse([
                'errors' => $errors,
                'message' => 'Validation failed'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);

            $event->setResponse($response);
        }
    }
}
