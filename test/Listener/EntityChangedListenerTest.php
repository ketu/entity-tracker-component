<?php
namespace Hostnet\Component\EntityTracker\Listener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;

/**
 * Listener for the Entities that use the Mutation Annotation.
 *
 * @author Yannick de Lange <ydelange@hostnet.nl>
 * @author Iltar van der Berg <ivanderberg@hostnet.nl>
 * @covers Hostnet\Component\EntityTracker\Listener\EntityChangedListener
 */
class EntityChangedListenerTest extends \PHPUnit_Framework_TestCase
{
    private $meta_annotation_provider;
    private $meta_mutation_provider;
    private $listener;
    private $event_manager;
    private $em;

    public function setUp()
    {
        $this->meta_annotation_provider = $this
            ->getMockBuilder('Hostnet\Component\EntityTracker\Provider\EntityAnnotationMetadataProvider')
            ->disableOriginalConstructor()
            ->getMock();

        $this->meta_mutation_provider = $this
            ->getMockBuilder('Hostnet\Component\EntityTracker\Provider\EntityMutationMetadataProvider')
            ->disableOriginalConstructor()
            ->getMock();

        $this->em = $this
            ->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->event = $this
            ->getMockBuilder('Doctrine\ORM\Event\PreFlushEventArgs')
            ->disableOriginalConstructor()
            ->getMock();

        $this->event_manager = $this
            ->getMockBuilder('Doctrine\Common\EventManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->em
            ->expects($this->any())
            ->method('getEventManager')
            ->willReturn($this->event_manager);

        $this->listener = new EntityChangedListener(
            $this->meta_annotation_provider,
            $this->meta_mutation_provider
        );
    }

    public function testPreFlushNoAnnotation()
    {
        $entity = new \stdClass();
        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getFullChangeSet')
            ->willReturn([$this->genericEntityDataProvider($entity)]);

        $this->meta_annotation_provider
            ->expects($this->once())
            ->method('isTracked')
            ->willReturn(false);

        $this->meta_mutation_provider
            ->expects($this->never())
            ->method('isEntityManaged');

        $this->event_manager
            ->expects($this->never())
            ->method('dispatchEvent');

        $this->listener->preFlush(new PreFlushEventArgs($this->em));
    }

    public function testPreFlushEmptyChanges()
    {
        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getFullChangeSet')
            ->willReturn([[]]);

        $this->meta_annotation_provider
            ->expects($this->never())
            ->method('isTracked')
            ->willReturn(false);

        $this->meta_mutation_provider
            ->expects($this->never())
            ->method('isEntityManaged');

        $this->event_manager
            ->expects($this->never())
            ->method('dispatchEvent');

        $this->listener->preFlush(new PreFlushEventArgs($this->em));
    }

    public function testPreFlushUnmanaged()
    {
        $entity = new \stdClass();
        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getFullChangeSet')
            ->willReturn($this->genericEntityDataProvider($entity));

        $this->meta_annotation_provider
            ->expects($this->once())
            ->method('isTracked')
            ->willReturn(true);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('isEntityManaged')
            ->willReturn(false);

        $this->meta_mutation_provider
            ->expects($this->never())
            ->method('createOriginalEntity');

        $this->event_manager
            ->expects($this->never())
            ->method('dispatchEvent');

        $this->listener->preFlush(new PreFlushEventArgs($this->em));
    }

    public function testPreFlushWithoutMutatedFields()
    {
        $entity   = new \stdClass();
        $original = new \stdClass();

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getFullChangeSet')
            ->willReturn($this->genericEntityDataProvider($entity));

        $this->meta_annotation_provider
            ->expects($this->once())
            ->method('isTracked')
            ->willReturn(true);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('isEntityManaged')
            ->willReturn(true);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('createOriginalEntity')
            ->willReturn($original);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getMutatedFields')
            ->willReturn([]);

        $this->event_manager
            ->expects($this->never())
            ->method('dispatchEvent');

        $this->listener->preFlush(new PreFlushEventArgs($this->em));
    }

    public function testPreFlushWithMutatedFields()
    {
        $entity       = new \stdClass();
        $entity->id   = 1;
        $original     = new \stdClass();
        $original->id = 0;

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getFullChangeSet')
            ->willReturn($this->genericEntityDataProvider($entity));

        $this->meta_annotation_provider
            ->expects($this->once())
            ->method('isTracked')
            ->willReturn(true);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('isEntityManaged')
            ->willReturn(true);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('createOriginalEntity')
            ->willReturn($original);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getMutatedFields')
            ->willReturn(['id']);

        $this->event_manager
            ->expects($this->once())
            ->method('dispatchEvent')
            ->with('entityChanged', $this->isInstanceof('Hostnet\Component\EntityTracker\Event\EntityChangedEvent'));

        $this->listener->preFlush(new PreFlushEventArgs($this->em));
    }

    public function testPreFlushWithProxy()
    {
        $entity = $this->getMock('Doctrine\ORM\Proxy\Proxy');

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('getFullChangeSet')
            ->willReturn($this->genericEntityDataProvider($entity));

        $this->meta_annotation_provider
            ->expects($this->once())
            ->method('isTracked')
            ->willReturn(true);

        $this->meta_mutation_provider
            ->expects($this->once())
            ->method('isEntityManaged')
            ->willReturn(true);

        $this->event_manager
            ->expects($this->never())
            ->method('dispatchEvent');

        $this->listener->preFlush(new PreFlushEventArgs($this->em));
    }

    /**
     * @param  mixed $entity
     * @return array[]
     */
    private function genericEntityDataProvider($entity)
    {
        return [
            get_class($entity) => [$entity]
        ];
    }
}
