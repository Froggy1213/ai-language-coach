<?php

namespace Tests\Unit\GraphQL;

use App\Enums\AssessmentStatus;
use App\Enums\CefrLevel;
use App\Enums\LessonCardStatus;
use App\Enums\RoadmapStatus;
use App\Enums\VoiceSessionStatus;
use BackedEnum;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumValueDefinitionNode;
use GraphQL\Language\Parser;
use PHPUnit\Framework\TestCase;

class CodegenEnumsTest extends TestCase
{
    /**
     * Map of GraphQL enum names to their backing PHP enum classes.
     *
     * @var array<string, class-string<BackedEnum>>
     */
    private const array PROJECT_ENUMS = [
        'CefrLevel' => CefrLevel::class,
        'RoadmapStatus' => RoadmapStatus::class,
        'LessonCardStatus' => LessonCardStatus::class,
        'AssessmentStatus' => AssessmentStatus::class,
        'VoiceSessionStatus' => VoiceSessionStatus::class,
    ];

    public function test_codegen_enums_sdl_matches_php_enums(): void
    {
        $path = dirname(__DIR__, 3).'/graphql/codegen-enums.graphql';
        $this->assertFileExists($path);

        $content = (string) file_get_contents($path);
        $document = Parser::parse($content);

        /** @var array<string, list<string>> $fileEnums */
        $fileEnums = [];

        foreach ($document->definitions as $definition) {
            $this->assertInstanceOf(
                EnumTypeDefinitionNode::class,
                $definition,
                'codegen-enums.graphql should contain only enum definitions.'
            );

            $enumName = $definition->name->value;
            $values = [];

            foreach ($definition->values as $valueDefinition) {
                $this->assertInstanceOf(EnumValueDefinitionNode::class, $valueDefinition);
                $values[] = $valueDefinition->name->value;
            }

            $fileEnums[$enumName] = $values;
        }

        $this->assertSame(
            array_keys(self::PROJECT_ENUMS),
            array_keys($fileEnums),
            'The enums defined in codegen-enums.graphql do not match the expected project enums.'
        );

        foreach (self::PROJECT_ENUMS as $name => $enumClass) {
            $expectedValues = array_map(
                static fn (BackedEnum $case): string => (string) $case->value,
                $enumClass::cases()
            );

            $this->assertSame(
                $expectedValues,
                $fileEnums[$name],
                "Enum values for {$name} in codegen-enums.graphql do not match {$enumClass}."
            );
        }
    }
}
