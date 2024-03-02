<?php

namespace Symfony\Flex;

class CompositeRecipeProvider implements RecipeProviderInterface
{
    /**
     * @var RecipeProviderInterface[]
     */
    private array $recipeProviders;

    /**
     * @param RecipeProviderInterface[] $recipeProviders
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(array $recipeProviders)
    {
        $this->recipeProviders = array_reduce(
            $recipeProviders,
            function (array $providers, RecipeProviderInterface $provider) {
                if (self::class == $provider::class) {
                    throw new \InvalidArgumentException('You cannot add an instance of this provider to itself.');
                }
                $providers[$provider::class] = $provider;

                return $providers;
            },
            []);
    }

    /**
     * This method adds an instance RecipeProviderInterface to this provider.
     * You can only have one instance per class registered in this provider.
     *
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function add(RecipeProviderInterface $recipeProvider): self
    {
        if (self::class == $recipeProvider::class) {
            throw new \InvalidArgumentException('You cannot add an instance of this provider to itself.');
        }
        if (isset($this->recipeProviders[$recipeProvider::class])) {
            throw new \InvalidArgumentException('Given Provider has been added already.');
        }
        $this->recipeProviders[] = $recipeProvider;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return array_reduce($this->recipeProviders, function (bool $isEnabled, RecipeProviderInterface $provider) { return $provider->isEnabled() && $isEnabled; }, true);
    }

    /**
     * {@inheritDoc}
     */
    public function disable(): void
    {
        array_walk($this->recipeProviders, function (RecipeProviderInterface $provider) { $provider->disable(); });
    }

    /**
     * {@inheritDoc}
     */
    public function getVersions(): array
    {
        return array_reduce($this->recipeProviders, function (array $carry, RecipeProviderInterface $provider) { return array_merge($carry, $provider->getVersions()); }, []);
    }

    /**
     * {@inheritDoc}
     */
    public function getAliases(): array
    {
        return array_reduce($this->recipeProviders, function (array $carry, RecipeProviderInterface $provider) { return array_merge($carry, $provider->getAliases()); }, []);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecipes(array $operations): array
    {
        return array_reduce($this->recipeProviders, function (array $carry, RecipeProviderInterface $provider) use ($operations) { return array_merge_recursive($carry, $provider->getRecipes($operations)); }, []);
    }

    /**
     * {@inheritDoc}
     */
    public function removeRecipeFromIndex(string $packageName, string $version): void
    {
        array_walk($this->recipeProviders, function (RecipeProviderInterface $provider) use ($packageName, $version) { $provider->removeRecipeFromIndex($packageName, $version); });
    }

    public function getSessionId(): string
    {
        return implode(' ', array_reduce(
            $this->recipeProviders,
            function (array $carry, RecipeProviderInterface $provider) {
                $carry[] = $provider::class.'=>'.$provider->getSessionId();

                return $carry;
            },
            []));
    }
}
