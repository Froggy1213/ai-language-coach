<?php

namespace App\GraphQL\Mutations;

use App\Assessments\AssessmentAudioStorage;
use App\Models\User;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

final class CreateAssessmentUploadUrl
{
    public function __construct(private readonly AssessmentAudioStorage $audio) {}

    /**
     * @param  array{contentType: string}  $args
     * @return array{uploadUrl: string, fileUrl: string, fields: list<array{name: string, value: string}>}
     */
    public function __invoke(mixed $root, array $args, GraphQLContext $context): array
    {
        // `@guard` has already resolved the user; the object key is namespaced
        // by their id, so one learner can never overwrite another's recording.
        $user = $context->user();
        assert($user instanceof User);

        $upload = $this->audio->presignUpload($user, $args['contentType']);

        // A form field has no natural name of its own in JSON, so the map the
        // storage returns is flattened into the pair list the SDL describes.
        $fields = [];

        foreach ($upload->fields as $name => $value) {
            $fields[] = ['name' => $name, 'value' => $value];
        }

        return [
            'uploadUrl' => $upload->uploadUrl,
            'fileUrl' => $upload->fileUrl,
            'fields' => $fields,
        ];
    }
}
