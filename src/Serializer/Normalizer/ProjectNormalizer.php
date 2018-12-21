<?php

namespace Harmony\Flex\Serializer\Normalizer;

use Harmony\Flex\Platform\Model\Project;
use Harmony\Flex\Platform\Model\ProjectDatabase;
use Symfony\Component\Serializer\Exception\BadMethodCallException;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\RuntimeException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Class ProjectNormalizer
 *
 * @package Harmony\Flex\Serializer\Normalizer
 */
class ProjectNormalizer implements DenormalizerInterface, DenormalizerAwareInterface
{

    use DenormalizerAwareTrait;

    /**
     * Denormalizes data back into an object of the given class.
     *
     * @param mixed  $data    Data to restore
     * @param string $class   The expected class to instantiate
     * @param string $format  Format the given data was extracted from
     * @param array  $context Options available to the denormalizer
     *
     * @return object
     * @throws BadMethodCallException   Occurs when the normalizer is not called in an expected context
     * @throws InvalidArgumentException Occurs when the arguments are not coherent or not supported
     * @throws UnexpectedValueException Occurs when the item cannot be hydrated with the given data
     * @throws ExtraAttributesException Occurs when the item doesn't have attribute to receive given data
     * @throws LogicException           Occurs when the normalizer is not supposed to denormalize
     * @throws RuntimeException         Occurs if the class cannot be instantiated
     * @throws \Exception
     */
    public function denormalize($data, $class, $format = null, array $context = [])
    {
        /** @var Project $project */
        $project = new $class();
        if (isset($data['name'])) {
            $project->setName($data['name']);
        }
        if (isset($data['description'])) {
            $project->setDescription($data['description']);
        }
        if (isset($data['slug'])) {
            $project->setSlug($data['slug']);
        }
        if (isset($data['url']) && null !== $data['url']) {
            $project->setUrl($data['url']);
        }
        if (isset($data['isSecured'])) {
            $project->setIsSecured($data['isSecured']);
        }
        if (isset($data['createdAt'])) {
            $project->setCreatedAt(new \DateTime($data['createdAt']));
        }
        if (isset($data['updatedAt'])) {
            $project->setUpdatedAt(new \DateTime($data['updatedAt']));
        }
        if (isset($data['databases']) && is_array($data['databases'])) {
            foreach ($data['databases'] as $db) {
                $database = new ProjectDatabase();
                $database->setScheme($db['scheme']);
                if (isset($db['host'])) {
                    $database->setHost($db['host']);
                }
                if (isset($db['name'])) {
                    $database->setName($db['name']);
                }
                if (isset($db['user'])) {
                    $database->setUser($db['user']);
                }
                if (isset($db['pass'])) {
                    $database->setPass($db['pass']);
                }
                if (isset($db['port'])) {
                    $database->setPort($db['port']);
                }
                if (isset($db['path'])) {
                    $database->setPath($db['path']);
                }
                if (isset($db['memory'])) {
                    $database->setMemory($db['memory']);
                }
                if (isset($db['query'])) {
                    $database->setQuery($db['query']);
                }
                // Build database url formatted like
                $database->buildDatabaseUrl();

                switch ($database->getScheme()) {
                    case 'mongodb':
                        $database->setEnv(['MONGODB_URL' => $database->getUrl(), 'MONGODB_DB' => $database->getName()]);
                        break;
                    case 'mysql':
                    case 'pgsql':
                    case 'sqlite':
                        $database->setEnv(['DATABASE_URL' => $database->getUrl()]);
                        break;
                }

                $project->addDatabase($database);
            }
        }

        return $project;
    }

    /**
     * Checks whether the given class is supported for denormalization by this normalizer.
     *
     * @param mixed  $data   Data to denormalize from
     * @param string $type   The class to which the data should be denormalized
     * @param string $format The format being deserialized from
     *
     * @return bool
     */
    public function supportsDenormalization($data, $type, $format = null)
    {
        return $type === Project::class;
    }
}