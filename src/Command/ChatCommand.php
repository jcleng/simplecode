<?php

namespace SimpleCode\Command;

use SimpleCode\LLM\Client as LLMClient;
use SimpleCode\Tool\ToolRegistry;
use SimpleCode\Util\Config;
use SimpleCode\MCP\Client as MCPClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;

class ChatCommand extends Command
{
    protected static $defaultName = 'chat';
    private const SYSTEM_PROMPT = <<<'PROMPT'

[SuperAssistant Operational Instructions][IMPORTANT]

<system>
You are SuperAssistant whose capabilities are to invoke functions by the help of user and make the best use of it during your assistance, a knowledgeable assistant focused on answering questions and providing information on any topics.
SuperAssitant should ask user to execute the function calls and get back the result of the function execution. Your ONlY job is to provide the user with the correct jsonl script and let user execute that and ask for the output.

Function Call Structure:
- All function calls should be wrapped in ```jsonl``` codeblocks tags like ```jsonl ... ``` in a NEW LINE. This is strict requirement.
- Use JSON array format for function calls
- Each function call is a JSON Lines object with "name", "call_id", and "parameters" properties
- Parameters are provided as a JSON Lines object with parameter names as keys
- Required parameters must always be included
- Optional parameters should only be included when needed

The instructions regarding function calls specify that:
- Use a JSON Lines object with "name" property specifying the function name.
- The function call must include a "call_id" property with a unique identifier.
- Parameters for the function should be included as a "parameters" object within the function call.
- Include all required parameters for each function call, while optional parameters should only be included when necessary.
- Do not refer to function/tool names when speaking directly to users - focus on what I'm doing rather than the tool I'm using.
- When invoking a function, ensure all necessary context is provided for the function to execute properly.
- Each function call should represent a single, complete function call with all its relevant parameters.
- DO not generate any function calls in your thinking/reasoning process, because those will be interpreted as a function call and executed. Just formulate the correct parameters for the function call.
- Ask user to execute the function calls by the help of user and get back the result of the function execution.

The instructions regarding 'call_id':
- It is a unique identifier for the function call.
- It is a number that is incremented by 1 for each new function call, starting from 1.

You can ask user to invoke one or more functions by writing a JSON Lines code block like the following as part of your reply to the user, MAKE SURE TO INVOKE ONLY ONE FUNCTION AT A TIME, It should be a JSON Lines code block like this:

<example_function_call>
### Add New Line Here
```jsonl
{"type": "function_call_start", "name": "function_name", "call_id": 1}
{"type": "description", "text": "Short 1 line of what this function does"}
{"type": "parameter", "key": "parameter_1", "value": "value_1"}
{"type": "parameter", "key": "parameter_2", "value": "value_2"}
{"type": "function_call_end", "call_id": 1}
```
</example_function_call>

When a user makes a request:
1. ALWAYS analyze what function calls would be appropriate for the task
2. ALWAYS format your function call usage EXACTLY as specified in the schema
3. NEVER skip required parameters in function calls
4. NEVER invent functions that aren't available to you
5. ALWAYS wait for function call execution results before continuing
6. After invoking a function, STOP.
7. NEVER invoke multiple functions in a single response
8. DO NOT STRICTLY GENERATE or form function results.
9. DO NOT use any python or custom tool code for invoking functions, use ONLY the specified JSON Lines format.

Answer the user's request using the relevant tool(s), if they are available. Check that all the required parameters for each tool call are provided or can reasonably be inferred from context. IF there are no relevant tools or there are missing values for required parameters, ask the user to supply these values; otherwise proceed with the tool calls. If the user provides a specific value for a parameter (for example provided in quotes), make sure to use that value EXACTLY. DO NOT make up values for or ask about optional parameters. Carefully analyze descriptive terms in the request as they may indicate required parameter values that should be included even if not explicitly quoted.




<response_format>

<thoughts optional="true">
User is asking...
My Thoughts ...
Observations made ...
Solutions i plan to use ...
Best function for this task ... with call id call_id to be used $CALL_ID + 1 = $CALL_ID
</thoughts>

```jsonl
{"type": "function_call_start", "name": "function_name", "call_id": 1}
{"type": "description", "text": "Short 1 line of what this function does"}
{"type": "parameter", "key": "parameter_1", "value": "value_1"}
{"type": "parameter", "key": "parameter_2", "value": "value_2"}
{"type": "function_call_end", "call_id": 1}
```

</response_format>

Do not use <thoughts> tag in your output, that is just output format reference to where to start and end your output. Format thoughts above in a nice paragraph explaining your thought process before the function call, need not be exact lines but just the flow of thought, You can skip these thoughts if not required for a simple task and directly use the json function call format.


<\system>

IMPORTANT: You need to place function call jsonl tags in proper jsonl code block like:

```jsonl
{"type": "function_call_start", "name": "function_name", "call_id": 1}
{"type": "description", "text": "Short 1 line of what this function does"}
{"type": "parameter", "key": "parameter_1", "value": "value_1"}
{"type": "parameter", "key": "parameter_2", "value": "value_2"}
{"type": "function_call_end", "call_id": 1}
```
function_name不要加前缀mcphub,比如: desktop-commander-list_directory;
每次一次只能回复一个jsonl块,回复jsonl指令前先检查数据格式是否正确,比如包含function_call_start/function_call_end以及call_id,且大括号匹配,如果收到Error: message, 多半是格式错误,需要纠正格式重新执行
Now ask user to use these jsonl lines and get back the result of the function execution



User Interaction Starts here:
你是谁, 会干什么

PROMPT;

    protected function configure(): void
    {
        $this->setDescription('Start an interactive chat session with the LLM agent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('SimpleCode - LLM CLI Editor');

        $config = new Config();
        if (!$config->getApiKey()) {
            $io->error('No API key found. Set OPENCODE_API_KEY environment variable or run: simplecode config set api_key <your-key>');
            return Command::FAILURE;
        }

        $llm = new LLMClient($config);
        $tools = new ToolRegistry();
        $mcp = new MCPClient('http://localhost:3006/mcp');

        // Initialize MCP and get available tools
        $io->write('<info>🔄 Initializing MCP server...</info>');
        try {
            $mcp->init();
            $io->writeln(' <info>Done!</info>');
            $io->writeln(sprintf('Found %d MCP tools', count($mcp->getTools())));
        } catch (\Exception $e) {
            $io->writeln(' <comment>Failed: ' . $e->getMessage() . '</comment>');
        }

        // Build system prompt with MCP tools info
        $systemPrompt = self::SYSTEM_PROMPT;
        $mcpToolsInfo = $mcp->getToolsInfo();
        if ($mcpToolsInfo) {
            $systemPrompt .= "\n" . $mcpToolsInfo;
        }

        $llm->addMessage(['role' => 'system', 'content' => $systemPrompt]);
        $llm->setTools($tools->getSchemas());

        $io->writeln('Enter your task (or "quit" to exit):');
        $io->newLine();

        while (true) {
            if (extension_loaded('readline')) {
                $userInput = readline('👨 ');
                if ($userInput === false) {
                    $userInput = null;
                }
            } else {
                $userInput = $io->ask('👨');
            }
            if ($userInput === null || strtolower($userInput) === 'quit') {
                break;
            }

            if (extension_loaded('readline') && $userInput !== '') {
                readline_add_history($userInput);
            }

            $llm->addMessage(['role' => 'user', 'content' => $userInput]);

            $this->processConversation($llm, $tools, $mcp, $io);
        }

        $io->success('Goodbye!');
        return Command::SUCCESS;
    }

    private function processConversation(LLMClient $llm, ToolRegistry $tools, MCPClient $mcp, SymfonyStyle $io): void
    {
        $maxIterations = 10;

        for ($i = 0; $i < $maxIterations; $i++) {
            $this->showThinking($io);
            $fullContent = '';
            $hasContent = false;

            $response = $llm->chatStream($tools->getSchemas(), function ($chunk) use ($io, &$fullContent, &$hasContent) {
                if (!$hasContent) {
                    $hasContent = true;
                    $this->clearThinking($io);
                }
                $fullContent .= $chunk;
                $io->write($chunk);
            });

            if ($hasContent) {
                $io->newLine(2);
            } else {
                $io->write("\033[2K\r");
            }

            if (!isset($response['choices'][0]['message'])) {
                $io->error('Invalid response from LLM');
                break;
            }

            $message = $response['choices'][0]['message'];

            if (isset($message['tool_calls']) && !empty($message['tool_calls'])) {
                $message['content'] = $fullContent ?: null;
                $llm->addMessage($message);

                foreach ($message['tool_calls'] as $toolCall) {
                    $toolName = $toolCall['function']['name'];
                    $params = json_decode($toolCall['function']['arguments'], true);

                    $io->writeln("<comment>🔧 Using tool: $toolName</comment>");
                    $result = $tools->execute($toolName, $params);
                    $io->writeln($result);
                    $io->newLine();

                    $llm->addMessage([
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => $result,
                    ]);
                }
                continue;
            }

            if ($fullContent) {
                $llm->addMessage(['role' => 'assistant', 'content' => $fullContent]);

                // Check for MCP JSONL code blocks and send to MCP server
                $mcpResult = $this->processMcpJsonl($fullContent, $mcp, $io);
                if ($mcpResult !== null) {
                    $llm->addMessage(['role' => 'user', 'content' => $mcpResult]);
                    continue;
                }
            }
            break;
        }
    }

    /**
     * Detect JSONL code blocks in content and send to MCP server
     * Returns MCP response if JSONL was found, null otherwise
     */
    private function processMcpJsonl(string $content, MCPClient $mcp, SymfonyStyle $io): ?string
    {
        // Match ```jsonl ... ``` code blocks
        if (!preg_match('/```jsonl\s*\n(.*?)```/s', $content, $matches)) {
            return null;
        }

        $jsonl = trim($matches[1]);
        if (empty($jsonl)) {
            return null;
        }

        $io->writeln("<info>📡 Converting and sending to MCP server...</info>");

        // Convert SuperAssistant format to MCP format
        $mcpJsonl = $mcp->convertToMCP($jsonl);

        if (empty($mcpJsonl)) {
            $io->writeln("<error>Failed to convert JSONL format</error>");
            return null;
        }

        $io->writeln("<comment>Converted MCP request:</comment>");
        $io->writeln($mcpJsonl);
        $io->newLine();

        // Send to MCP server
        $formattedResponse = $mcp->send($mcpJsonl);

        $io->writeln("<info>📡 MCP Response:</info>");
        $io->writeln($formattedResponse);
        $io->newLine();

        return $formattedResponse;
    }

    private function showThinking(SymfonyStyle $io): void
    {
        $io->write('<info>🤖 Thinking...</info>');
    }

    private function clearThinking(SymfonyStyle $io): void
    {
        $io->write("\033[2K\r");
    }
}
