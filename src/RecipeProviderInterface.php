<?php

namespace Symfony\Flex;

use Composer\DependencyResolver\Operation\OperationInterface;

interface RecipeProviderInterface
{
    /**
     * If this RecipeProviderInterface is enabled or not.
     */
    public function isEnabled(): bool;

    /**
     * Disable this RecipeProviderInterface.
     */
    public function disable(): void;

    public function getVersions(): array;

    public function getAliases(): array;

    /**
     * Downloads recipes.
     *
     * @param OperationInterface[] $operations
     */
    public function getRecipes(array $operations): array;

    /**
     * Used to "hide" a recipe version so that the next most-recent will be returned.
     *
     * This is used when resolving "conflicts".
     */
    public function removeRecipeFromIndex(string $packageName, string $version): void;

    public function getSessionId(): string;
}
