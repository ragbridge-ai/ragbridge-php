<?php

declare(strict_types=1);

use Ragbridge\Exception\InvalidResponseException;
use Ragbridge\Exception\ValidationException;
use Ragbridge\Tests\Support\ClientFactory;
use Ragbridge\Tests\Support\Fixtures;
use Ragbridge\Tests\Support\Thrown;

describe('agent', function (): void {
    it('returns the answer, the sources and the steps taken', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(200, 'agent_response'));

        $result = $client->agent('How much leave and remote work do employees get?');

        expect($result->answer)->toContain('25 days')
            ->and($result->sources)->toHaveCount(2)
            ->and($result->sources[1]->chunkIndex)->toBe(7)
            ->and($result->steps)->toHaveCount(2)
            ->and($result->steps[1]->query)->toBe('How many remote work days are allowed?')
            ->and($result->steps[1]->results)->toBe(3)
            ->and($result->stepCount)->toBe(2);
    });

    it('posts only the question when no step limit is given', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'agent_response'));

        $client->agent('Q');

        $request = ClientFactory::lastRequest($http);

        expect($request->getMethod())->toBe('POST')
            ->and((string) $request->getUri())->toBe('http://localhost:8000/agent')
            ->and($request->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($request->getHeaderLine('Authorization'))->toBe('Bearer secret-key')
            ->and(json_decode((string) $request->getBody(), true))->toBe(['question' => 'Q']);
    });

    it('sends the step limit when it is set', function (): void {
        [$client, $http] = ClientFactory::answering(Fixtures::jsonResponse(200, 'agent_response'));

        $client->agent('Q', maxSteps: 2);

        expect(json_decode((string) ClientFactory::lastRequest($http)->getBody(), true))->toBe([
            'question' => 'Q',
            'max_steps' => 2,
        ]);
    });

    it('reports a rejected request as a validation error', function (): void {
        [$client] = ClientFactory::answering(Fixtures::jsonResponse(422, 'error_validation'));

        $e = Thrown::by(fn() => $client->agent('Q', maxSteps: 0), ValidationException::class);

        expect($e->statusCode())->toBe(422);
    });

    it('rejects a response that does not match the schema', function (): void {
        [$client] = ClientFactory::answering(Fixtures::response(200, '{"answer": "x", "sources": []}'));

        expect(fn() => $client->agent('Q'))->toThrow(InvalidResponseException::class, 'AgentResult');
    });
});
