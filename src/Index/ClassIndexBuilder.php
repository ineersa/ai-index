<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Index;

use Ineersa\AiIndex\Config\IndexConfig;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

final class ClassIndexBuilder
{
    private readonly \PhpParser\Parser $parser;
    private readonly PrettyPrinter $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->printer = new PrettyPrinter();
    }

    /**
     * @param array<string, array<string, array{callers: list<string>, callees: list<string>}>> $callGraph
     * @param array<string, array<string, mixed>> $diWiringByClass
     *
     * @return array{entries: list<array<string, mixed>>, skipReason: null|string}
     */
    public function build(
        string $phpFile,
        string $code,
        array $callGraph,
        array $diWiringByClass,
        IndexConfig $config,
    ): array {
        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            return [
                'entries' => [],
                'skipReason' => 'parse error',
            ];
        }

        if (!is_array($ast)) {
            return [
                'entries' => [],
                'skipReason' => 'parse error',
            ];
        }

        $namespace = $this->extractNamespace($ast);
        $classNodes = $this->findClassLikeNodes($ast);

        if ([] === $classNodes) {
            return [
                'entries' => [],
                'skipReason' => 'no classes',
            ];
        }

        $entries = [];

        foreach ($classNodes as $classNode) {
            if (!$classNode->name instanceof Node\Identifier) {
                continue;
            }

            $classInfo = $this->buildClassEntry($classNode, $namespace, $code);
            $fqcn = ('' !== $namespace ? $namespace.'\\' : '').$classInfo['className'];

            $methodEntries = [];
            foreach ($classInfo['methods'] as $method) {
                $entry = [
                    'method' => $method['name'],
                    'start' => $method['docStartLine'],
                    'end' => $method['endLine'],
                    'limit' => $method['endLine'] - $method['docStartLine'] + 1,
                    'symbolLine' => $method['symbolLine'],
                    'symbolColumn' => $method['symbolColumn'],
                    'signature' => $method['signature'],
                ];

                $callees = $callGraph[$fqcn][$method['name']]['callees'] ?? [];
                $callers = $callGraph[$fqcn][$method['name']]['callers'] ?? [];

                if ([] !== $callees) {
                    $entry['callees'] = $callees;
                }

                if ([] !== $callers) {
                    $entry['callers'] = $callers;
                }

                $methodEntries[] = $entry;
            }

            $indexData = [
                'spec' => (string) $config->indexSpec['file'],
                'file' => basename($phpFile),
                'class' => $fqcn,
                'type' => trim($classInfo['classModifiers'].' '.$classInfo['classType']),
            ];

            if ([] !== $classInfo['sections']) {
                $indexData['sections'] = $classInfo['sections'];
            }

            if ([] !== $classInfo['constructorInputs']) {
                $indexData['constructorInputs'] = $classInfo['constructorInputs'];
            }

            $wiring = $diWiringByClass[$fqcn] ?? [];
            if (is_array($wiring) && [] !== $wiring) {
                $indexData['wiring'] = $wiring;
            }

            if ([] !== $methodEntries) {
                $indexData['methods'] = $methodEntries;
            }

            $entries[] = $indexData;
        }

        if ([] === $entries) {
            return [
                'entries' => [],
                'skipReason' => 'no classes',
            ];
        }

        return [
            'entries' => $entries,
            'skipReason' => null,
        ];
    }

    /**
     * @param list<Node> $ast
     */
    private function extractNamespace(array $ast): string
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_ && null !== $node->name) {
                return $node->name->toString();
            }
        }

        return '';
    }

    /**
     * @param list<Node> $ast
     *
     * @return list<Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_>
     */
    private function findClassLikeNodes(array $ast): array
    {
        $finder = new NodeFinder();
        $types = [
            Node\Stmt\Class_::class,
            Node\Stmt\Trait_::class,
            Node\Stmt\Enum_::class,
            Node\Stmt\Interface_::class,
        ];

        $result = [];

        foreach ($types as $type) {
            /** @var list<Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_> $nodes */
            $nodes = $finder->findInstanceOf($ast, $type);
            $result = [...$result, ...$nodes];
        }

        return $result;
    }

    /**
     * @return array{
     *   className: string,
     *   classType: string,
     *   classModifiers: string,
     *   sections: list<array<string, int|string>>,
     *   constructorInputs: list<array{param: string, type: string, required: bool}>,
     *   methods: list<array{name: string, docStartLine: int, endLine: int, symbolLine: int, symbolColumn: int, signature: string}>
     * }
     */
    private function buildClassEntry(
        Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class,
        string $namespace,
        string $code,
    ): array {
        $classType = match (true) {
            $class instanceof Node\Stmt\Trait_ => 'trait',
            $class instanceof Node\Stmt\Enum_ => 'enum',
            $class instanceof Node\Stmt\Interface_ => 'interface',
            default => 'class',
        };

        $classModifiers = [];
        if ($class instanceof Node\Stmt\Class_) {
            if ($class->isReadonly()) {
                $classModifiers[] = 'readonly';
            }
            if ($class->isFinal()) {
                $classModifiers[] = 'final';
            }
            if ($class->isAbstract()) {
                $classModifiers[] = 'abstract';
            }
        }

        $sections = $this->extractClassSections($class);
        $constructorInputs = $this->extractConstructorInputs($this->findConstructorMethod($class));

        $methods = [];
        foreach ($class->stmts as $statement) {
            if (!$statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $doc = $statement->getDocComment();
            $docStartLine = $doc?->getStartLine() ?? $statement->getStartLine();
            $methodName = $statement->name->toString();

            $signature = trim(sprintf(
                '%s function %s(%s)',
                $this->methodModifiers($statement),
                $methodName,
                $this->buildMethodParams($statement),
            ));

            $returnType = $this->typeToString($statement->returnType);
            if ('' !== $returnType) {
                $signature .= ': '.$returnType;
            }

            $methods[] = [
                'name' => $methodName,
                'docStartLine' => $docStartLine,
                'endLine' => $statement->getEndLine(),
                'symbolLine' => $statement->name->getStartLine(),
                'symbolColumn' => $this->offsetToColumn($code, $statement->name->getStartFilePos()),
                'signature' => $signature,
            ];
        }

        return [
            'className' => $class->name?->toString() ?? ('' !== $namespace ? $namespace : 'AnonymousClass'),
            'classType' => $classType,
            'classModifiers' => implode(' ', $classModifiers),
            'sections' => $sections,
            'constructorInputs' => $constructorInputs,
            'methods' => $methods,
        ];
    }

    /**
     * @return list<array<string, int|string>>
     */
    private function extractClassSections(Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class): array
    {
        $sections = [];

        $classDoc = $class->getDocComment();
        if (null !== $classDoc) {
            $classDocStart = $classDoc->getStartLine();
            $classDocEnd = $classDoc->getEndLine();

            $sections[] = [
                'kind' => 'classDoc',
                'start' => $classDocStart,
                'end' => $classDocEnd,
                'limit' => $classDocEnd - $classDocStart + 1,
            ];
        }

        $constantNodes = [];
        $propertyNodes = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof Node\Stmt\ClassConst || $statement instanceof Node\Stmt\EnumCase) {
                $constantNodes[] = $statement;
            }

            if ($statement instanceof Node\Stmt\Property) {
                $propertyNodes[] = $statement;
            }
        }

        $constantsSection = $this->buildSectionFromNodes('constants', $constantNodes);
        if (null !== $constantsSection) {
            $sections[] = $constantsSection;
        }

        $propertiesSection = $this->buildSectionFromNodes('properties', $propertyNodes);
        if (null !== $propertiesSection) {
            $sections[] = $propertiesSection;
        }

        $constructor = $this->findConstructorMethod($class);
        if (null !== $constructor) {
            $constructorDoc = $constructor->getDocComment();
            $constructorStart = $constructorDoc?->getStartLine() ?? $constructor->getStartLine();
            $constructorEnd = $constructor->getEndLine();

            $constructorSection = [
                'kind' => 'constructor',
                'start' => $constructorStart,
                'end' => $constructorEnd,
                'limit' => $constructorEnd - $constructorStart + 1,
                'signatureLine' => $constructor->name->getStartLine(),
            ];

            if (null !== $constructorDoc) {
                $constructorSection['commentStart'] = $constructorDoc->getStartLine();
            }

            $sections[] = $constructorSection;
        }

        return $sections;
    }

    /**
     * @param list<Node> $nodes
     *
     * @return array{kind: string, start: int, end: int, limit: int}|null
     */
    private function buildSectionFromNodes(string $kind, array $nodes): ?array
    {
        if ([] === $nodes) {
            return null;
        }

        $start = min(array_map(static fn (Node $node): int => $node->getStartLine(), $nodes));
        $end = max(array_map(static fn (Node $node): int => $node->getEndLine(), $nodes));

        return [
            'kind' => $kind,
            'start' => $start,
            'end' => $end,
            'limit' => $end - $start + 1,
        ];
    }

    private function findConstructorMethod(
        Node\Stmt\Class_|Node\Stmt\Trait_|Node\Stmt\Enum_|Node\Stmt\Interface_ $class,
    ): ?Node\Stmt\ClassMethod {
        foreach ($class->stmts as $statement) {
            if (!$statement instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            if ('__construct' === strtolower($statement->name->toString())) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * @return list<array{param: string, type: string, required: bool}>
     */
    private function extractConstructorInputs(?Node\Stmt\ClassMethod $constructor): array
    {
        if (null === $constructor) {
            return [];
        }

        $inputs = [];
        foreach ($constructor->getParams() as $param) {
            $type = $this->typeToString($param->type);

            $inputs[] = [
                'param' => '$'.(string) $param->var->name,
                'type' => '' !== $type ? $type : 'mixed',
                'required' => null === $param->default && !$param->variadic,
            ];
        }

        return $inputs;
    }

    private function methodModifiers(Node\Stmt\ClassMethod $method): string
    {
        $modifiers = [];

        if ($method->isFinal()) {
            $modifiers[] = 'final';
        }

        if ($method->isAbstract()) {
            $modifiers[] = 'abstract';
        }

        if ($method->isStatic()) {
            $modifiers[] = 'static';
        }

        $modifiers[] = $this->methodVisibility($method);

        return implode(' ', $modifiers);
    }

    private function methodVisibility(Node\Stmt\ClassMethod $method): string
    {
        if ($method->isPublic()) {
            return 'public';
        }

        if ($method->isProtected()) {
            return 'protected';
        }

        return 'private';
    }

    private function typeToString(Node\ComplexType|Node\Identifier|Node\Name|null $type): string
    {
        if (null === $type) {
            return '';
        }

        if ($type instanceof Node\NullableType) {
            return '?'.$this->typeToString($type->type);
        }

        if ($type instanceof Node\UnionType) {
            $types = [];
            foreach ($type->types as $unionType) {
                $types[] = $this->typeToString($unionType);
            }

            return implode('|', $types);
        }

        if ($type instanceof Node\IntersectionType) {
            $types = [];
            foreach ($type->types as $intersectionType) {
                $types[] = $this->typeToString($intersectionType);
            }

            return implode('&', $types);
        }

        return $type->toString();
    }

    private function buildMethodParams(Node\Stmt\ClassMethod $method): string
    {
        $parts = [];

        foreach ($method->getParams() as $param) {
            $chunks = [];
            $paramType = $this->typeToString($param->type);
            if ('' !== $paramType) {
                $chunks[] = $paramType;
            }

            if ($param->byRef) {
                $chunks[] = '&';
            }

            if ($param->variadic) {
                $chunks[] = '...';
            }

            $chunks[] = '$'.(string) $param->var->name;

            if (null !== $param->default) {
                $chunks[] = '= '.$this->printer->prettyPrintExpr($param->default);
            }

            $parts[] = implode(' ', $chunks);
        }

        return implode(', ', $parts);
    }

    private function offsetToColumn(string $code, int $offset): int
    {
        if ($offset < 0) {
            return 1;
        }

        $before = substr($code, 0, $offset);
        $lineStartOffset = strrpos($before, "\n");

        if (false === $lineStartOffset) {
            return $offset + 1;
        }

        return $offset - $lineStartOffset;
    }
}
