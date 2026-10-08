<?php

namespace App\GraphQL\Validation;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Introspection;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * The configured depth limit, with introspection exempted from the count.
 *
 * The standard introspection query is deep by design: it walks the *type* graph,
 * not the data graph, and it is issued by tooling rather than by a learner —
 * Lighthouse's own `MakesGraphQLRequests::introspectType()` helper sends it, and
 * a limit sized for product queries refuses it.
 *
 * webonyx already treats `__schema` this way in `QueryComplexity` ("Exclude
 * __schema field and all nested content from complexity calculation"), so this
 * rule simply matches the library instead of forcing every consumer to raise the
 * limit until introspection fits.
 *
 * `__type` is deliberately NOT exempt: it is the self-referencing field that can
 * be nested to any depth, and it is what QueryLimitsTest nests to prove the limit
 * still bites. Introspection as a whole has its own switch,
 * `lighthouse.security.disable_introspection`.
 */
final class IntrospectionAwareQueryDepth extends QueryDepth
{
    protected function nodeDepth(Node $node, int $depth = 0, int $maxDepth = 0): int
    {
        if ($node instanceof FieldNode && $node->name->value === Introspection::SCHEMA_FIELD_NAME) {
            return $maxDepth;
        }

        return parent::nodeDepth($node, $depth, $maxDepth);
    }
}
