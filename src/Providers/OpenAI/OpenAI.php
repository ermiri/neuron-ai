<?php

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Messages\Message;
use GuzzleHttp\Client;
use NeuronAI\HasGuzzleClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\HandleWithTools;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;

class OpenAI implements AIProviderInterface
{
    use HasGuzzleClient;
    use HandleWithTools;
    use HandleChat;
    use HandleStream;
    use HandleStructured;

    /**
     * The main URL of the provider API.
     *
     * @var string
     */
    protected string $baseUri = 'https://api.openai.com/v1';

    /**
     * System instructions.
     * https://platform.openai.com/docs/api-reference/chat/create
     *
     * @var ?string
     */
    protected ?string $system;

    /**
     * The component responsible for mapping the NeuronAI Message to the AI provider format.
     *
     * @var MessageMapperInterface
     */
    protected MessageMapperInterface $messageMapper;

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
    ) {
        $this->client = new Client([
            'base_uri' => trim($this->baseUri, '/').'/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ]);
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        if (!isset($this->messageMapper)) {
            $this->messageMapper = new MessageMapper();
        }
        return $this->messageMapper;
    }

    public function generateToolsPayload(): array
    {
        return \array_map(function (ToolInterface $tool) {

            //try to get functions from the modules
            $fn = \Illuminate\Support\Arr::first(\ErmirShehaj\Ai\Classes\Helper::getAvailableFunctions(), function($val) use($tool) {

                return $val['name'] == $tool->getName();
            });

            if($fn) {

                return $payload = [
                    'type' => 'function',
                    'function' => $fn
                ];
            }
            
            $payload = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                        'required' => [],
                    ],
                ]
            ];

            $properties = \array_reduce($tool->getProperties(), function (array $carry, ToolProperty $property) {
                $carry[$property->getName()] = [
                    'description' => $property->getDescription(),
                    'type' => $property->getType(),
                ];

                if (!empty($property->getEnum())) {
                    $carry[$property->getName()]['enum'] = $property->getEnum();
                }

                return $carry;
            }, []);

            if (!empty($properties)) {
                $payload['function']['parameters'] = [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $tool->getRequiredProperties(),
                ];
            }

            return $payload;
        }, $this->tools);
    }

    protected function createToolCallMessage(array $message): Message
    {
        $tools = \array_map(function (array $item) {
            return $this->findTool($item['function']['name'])
                ->setInputs(
                    \json_decode($item['function']['arguments'], true)
                )
                ->setCallId($item['id']);
        }, $message['tool_calls']);

        $result = new ToolCallMessage(
            $message['content'],
            $tools
        );

        return $result->addMetadata('tool_calls', $message['tool_calls']);
    }
}
