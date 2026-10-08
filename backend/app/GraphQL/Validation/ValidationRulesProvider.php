<?php

namespace App\GraphQL\Validation;

use GraphQL\Validator\Rules\QueryDepth;
use Nuwave\Lighthouse\Execution\CacheableValidationRulesProvider;

/**
 * Lighthouse's cacheable validation rules with the depth rule replaced.
 *
 * Registered by AppServiceProvider in place of Lighthouse's default provider so
 * that `lighthouse.security.max_query_depth` keeps applying to product queries
 * while introspection is exempted — see IntrospectionAwareQueryDepth for why.
 *
 * Only the depth rule is touched: query complexity is not cacheable in
 * Lighthouse (it depends on variables) and is served by the parent's
 * `validationRules()` untouched.
 */
final class ValidationRulesProvider extends CacheableValidationRulesProvider
{
    public function cacheableValidationRules(): array
    {
        $rules = parent::cacheableValidationRules();

        $maxQueryDepth = (int) $this->configRepository->get('lighthouse.security.max_query_depth', 0);

        if ($maxQueryDepth > 0) {
            $rules[QueryDepth::class] = new IntrospectionAwareQueryDepth($maxQueryDepth);
        }

        return $rules;
    }
}
