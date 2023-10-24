<?php

declare(strict_types=1);

namespace Luzrain\TelegramBotBundle\TelegramBot;

use Luzrain\TelegramBotApi\ClientApi;
use Luzrain\TelegramBotApi\Event;
use Luzrain\TelegramBotApi\Exception\TelegramCallbackException;
use Luzrain\TelegramBotApi\Method;
use Luzrain\TelegramBotApi\Type\Update;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class UpdateHandler
{
    public function __construct(
        private ClientApi $client,
        private ServiceLocator $serviceLocator,
        array $controllersMap,
    ) {
        foreach ($controllersMap as ['event' => $event, 'value' => $value, 'controller' => $controller]) {
            $this->client->on($this->createClosure($event, $value, $controller));
        }
    }

    /**
     * @throws TelegramCallbackException
     */
    public function handle(Update $update): Method|null
    {
        return $this->client->handle($update);
    }

    /**
     * @param class-string<Event> $event
     */
    private function createClosure(string $event, string $value, string $controller): Event
    {
        return match ($event) {
            Event\Command::class => new $event($value, function (object $update, string ...$params) use ($controller) {
                return $this->runController($controller, $update, $params);
            }),
            Event\NamedCallbackQuery::class => new $event($value, function (object $update) use ($controller) {
                return $this->runController($controller, $update);
            }),
            default => new $event(function (object $update) use ($controller) {
                return $this->runController($controller, $update);
            }),
        };
    }

    private function runController(string $controller, object $update, array $params = []): mixed
    {
        /** @psalm-suppress PossiblyUndefinedArrayOffset */
        [$service, $method] = explode('::', $controller, 2);
        $controllerService = $this->serviceLocator->get($service);

        if ($update instanceof Update) {
            $user = null;
            $chat = null;
            foreach ($update as $updateItem) {
                if (isset($updateItem->from)) {
                    $user = $updateItem->from;
                    $chat = $updateItem->chat;
                    break;
                }
            }
        } else {
            $user = $update->from ?? null;
            $chat = $update->chat ?? null;
        }

        if ($controllerService instanceof TelegramCommand && $user !== null) {
            $controllerService->setUser($user);
            $controllerService->setChat($chat);
        }

        return $controllerService->$method($update, ...$params);
    }
}
