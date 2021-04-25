<?php

namespace skh6075\damagetag;

use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use Ramsey\Uuid\Uuid;

final class DamageTag extends PluginBase implements Listener{

    protected function onEnable(): void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /** @return Player[] */
    private function getPlayersByRadius(Position $position, float $radius): array{
        $players = [];
        foreach ($position->getWorld()->getPlayers() as $player) {
            if ($position->distance($player->getPosition()) <= $radius)
                $players[] = $player;
        }

        return $players;
    }

    public function onEntityAttack(EntityDamageEvent $event): void{
        if ($event->isCancelled())
            return;

        /** @var EntityDamageByEntityEvent|EntityDamageByChildEntityEvent $event */
        if (!$this->isConsistentEvent($event))
            return;

        if (!($player = $event->getDamager()) instanceof Player)
            return;

        $this->placeDamageTag($event->getEntity()->getPosition(), $event->getFinalDamage());
    }

    private function isConsistentEvent(EntityDamageEvent $event): bool{
        return $event instanceof EntityDamageByChildEntityEvent or $event instanceof EntityDamageByEntityEvent;
    }

    public function placeDamageTag(Position $position, float $damage): void{
        $pk = new AddPlayerPacket();
        $pk->entityRuntimeId = $pk->entityUniqueId = Entity::nextRuntimeId();
        $pk->position = $position->add(0, 1, 0);
        $pk->uuid = Uuid::uuid4();
        $pk->item = ItemStackWrapper::legacy(ItemStack::null());
        $pk->username = TextFormat::GREEN . round($damage, 2);
        $pk->metadata = [
            EntityMetadataProperties::FLAGS => new LongMetadataProperty(1 << EntityMetadataFlags::IMMOBILE),
            EntityMetadataProperties::SCALE => new FloatMetadataProperty(0.01)
        ];

        $packet = RemoveActorPacket::create($pk->entityRuntimeId);

        $players = $this->getPlayersByRadius($position, 18.5);

        foreach ($players as $player)
            $player->getNetworkSession()->sendDataPacket($pk);

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($players, $packet): void{
            foreach ($players as $player)
                $player->getNetworkSession()->sendDataPacket($packet);
        }), 45);
    }
}