<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use XbNz\Gemini\AIPlatform\Contracts\GoogleAIPlatformInterface;
use XbNz\Gemini\AIPlatform\Contracts\PartContract;
use XbNz\Gemini\AIPlatform\DataTransferObjects\ContentDTO;
use XbNz\Gemini\AIPlatform\DataTransferObjects\Requests\GenerateContentRequestDTO;
use XbNz\Gemini\AIPlatform\Enums\HarmCategory;
use XbNz\Gemini\AIPlatform\Enums\Role;
use XbNz\Gemini\AIPlatform\Enums\SafetyThreshold;
use XbNz\Gemini\AIPlatform\ValueObjects\BlobPart;
use XbNz\Gemini\AIPlatform\ValueObjects\GenerationConfig;
use XbNz\Gemini\AIPlatform\ValueObjects\SafetySettings;
use XbNz\Gemini\AIPlatform\ValueObjects\TextPart;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

class GeminiCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:gemini-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(GoogleAIPlatformInterface $googleAIPlatform): void
    {
        $contentCollection = Collection::make();

        while (true) {
            table(
                ['Role', 'Message'],
                $contentCollection->map(function (ContentDTO $content) {
                    return [
                        $content->role->value,
                        $content->parts->first(fn (PartContract $part) => $part instanceof TextPart)->text ?? 'N/A',
                    ];
                })->toArray(),
            );

            $userInput = text('You: ');

            $fileUpload = confirm('Upload file?', false);

            if ($fileUpload === true) {
                $fileFqPaths = $this->promptForFiles();
            }

            $response = $googleAIPlatform->generateContent(
                new GenerateContentRequestDTO(
                    'publishers/google/models/gemini-experimental',
                    $contentCollection->push(
                        new ContentDTO(
                            Role::User,
                            Collection::make([
                                new TextPart($userInput),
                                ...Collection::make($fileFqPaths ?? [])
                                    ->map(fn (string $fileFqPath) => new BlobPart(
                                        File::mimeType($fileFqPath),
                                        base64_encode(File::get($fileFqPath))
                                    ))
                            ])
                        ),

                    ),
                    Collection::make([
                        new SafetySettings(HarmCategory::HarmCategoryHarassment, SafetyThreshold::BlockOnlyHigh),
                        new SafetySettings(HarmCategory::HarmCategoryHateSpeech, SafetyThreshold::BlockOnlyHigh),
                        new SafetySettings(HarmCategory::HarmCategorySexuallyExplicit, SafetyThreshold::BlockOnlyHigh),
                        new SafetySettings(HarmCategory::HarmCategoryDangerousContent, SafetyThreshold::BlockOnlyHigh),
                    ])
                )
            );


            if ($response->finishReason->consideredSuccessful() === false) {
                error('Gemini rejected your input. Please try again.');
                continue;
            }

            $modelResponse = $response->content;
            $contentCollection->push($modelResponse);
        }
    }

    private function promptForFiles(): array
    {
        return multisearch(
            'What files do you want to send to Gemini?',
            function (string $input) {
                if (is_dir($input)) {
                    $paths = explode(
                        PHP_EOL,
                        Process::path($input)
                            ->run('find . -maxdepth 1')
                            ->output()
                    );
                } else {
                    $paths = explode(
                        PHP_EOL,
                        Process::path('.')
                            ->run('find . -maxdepth 1')
                            ->output()
                    );
                }

                return Collection::make($paths)
                    ->map(fn (string $path) => Str::of($path)->replace('./', '/')
                        ->prepend($input)
                        ->value())
                    ->toArray();
            },
        );
    }
}
