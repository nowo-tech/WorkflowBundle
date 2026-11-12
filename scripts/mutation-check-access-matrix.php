<?php

declare(strict_types=1);

// Source mutation check of the 1.14 access-decision equivalence matrix (tests/Unit/Equivalence): applies one mutation
// of the access path at a time, runs the matrix, restores the file. Every mutation must be "killed".
// Usage (bundle root, in the PHP container): php scripts/mutation-check-access-matrix.php
$m = [
    ['src/EventListener/WorkflowUiAccessSubscriber.php', "        if (!\$event->isMainRequest()) {\n            return;\n        }\n", ''],
    ['src/EventListener/WorkflowUiAccessSubscriber.php', "WorkflowUiAccessCheckerInterface) {\n            return false;", "WorkflowUiAccessCheckerInterface) {\n            return true;"],
    ['src/EventListener/WorkflowUiAccessSubscriber.php', '&& !$this->accessPolicy->allows(', '&& $this->accessPolicy->allows('],
    ['src/EventListener/WorkflowUiAccessSubscriber.php', '|| $this->accessChecker->isGranted($request);', '&& $this->accessChecker->isGranted($request);'],
    ['src/EventListener/WorkflowUiAccessSubscriber.php', 'if (!$this->isGranted($event->getRequest())) {', 'if ($this->isGranted($event->getRequest()) && false) {'],
    ['src/Service/RoleBasedWorkflowUiAccessChecker.php', "if (\$this->requiredRoles === []) {\n            return false;", "if (\$this->requiredRoles === []) {\n            return true;"],
    ['src/Service/RoleBasedWorkflowUiAccessChecker.php', "                return true;\n            }\n        }\n\n        return false;", "                return true;\n            }\n        }\n\n        return true;"],
    ['src/Http/WorkflowUiAccessCheckFactory.php', '            self::actor($token),', '            null,'],
    ['src/Http/WorkflowUiAccessCheckFactory.php', '            $request->getPathInfo(),', "            '',"],
    ['src/Http/WorkflowUiAccessCheckFactory.php', 'is_array($routeParams) ? $routeParams : [],', '[],'],
    ['src/Http/WorkflowUiAccessCheckFactory.php', '            is_string($route) ? $route : null,', '            null,'],
    ['src/Http/WorkflowUiAccessCheckFactory.php', '$token instanceof SwitchUserToken ? $token->getOriginalToken()->getUserIdentifier() : null,', 'null,'],
    ['src/Http/WorkflowUiAccessCheckFactory.php', '            $token->getRoleNames(),', '            [],'],
    ['src/Http/WorkflowUiAccessCheckFactory.php', '            $request->getMethod(),', "            'GET',"],
    ['src/Http/WorkflowUiAccessCheckFactory.php', 'if (!$token instanceof TokenInterface || !$user instanceof UserInterface) {', 'if (!$token instanceof TokenInterface) {'],
    ['src/Model/WorkflowUiAccessCheck.php', '$kept[$name] = $value;', "\$kept[\$name] = (string) \$value . 'x';"],
    ['src/Model/WorkflowActor.php', '$this->roles = array_values($roles);', '$this->roles = [];'],
    ['src/Service/AllowAllWorkflowUiAccessChecker.php', "    public function allows(WorkflowUiAccessCheck \$check): bool\n    {\n        return true;", "    public function allows(WorkflowUiAccessCheck \$check): bool\n    {\n        return false;"],
    ['src/Service/DenyAllWorkflowUiAccessChecker.php', "    public function allows(WorkflowUiAccessCheck \$check): bool\n    {\n        return false;", "    public function allows(WorkflowUiAccessCheck \$check): bool\n    {\n        return true;"],
    ['src/Service/DenyAllWorkflowUiAccessChecker.php', "    public function isGranted(Request \$request): bool\n    {\n        return false;", "    public function isGranted(Request \$request): bool\n    {\n        return true;"],
];
$killed = 0;
foreach ($m as $i => [$file, $from, $to]) {
    $orig = file_get_contents($file);
    if (substr_count($orig, $from) !== 1) {
        echo "M{$i} NOT APPLICABLE ({$file})\n";
        continue;
    }
    file_put_contents($file, str_replace($from, $to, $orig));
    exec('vendor/bin/phpunit --no-progress --no-coverage --filter everyDecisionMatchesTheV1130Reference tests/Unit/Equivalence 2>&1', $out, $code);
    file_put_contents($file, $orig);
    $status = $code === 0 ? 'SURVIVED' : 'killed';
    if ($code !== 0) {
        ++$killed;
    }
    echo "M{$i} {$status}  {$file}\n";
    $out = [];
}
echo "killed {$killed} / " . count($m) . "\n";
exit($killed === count($m) ? 0 : 1);
