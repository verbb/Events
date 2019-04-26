<?php
namespace verbb\events\elements;

use verbb\events\Events;
use verbb\events\elements\db\EventQuery;
use verbb\events\helpers\EventHelper;
use verbb\events\helpers\TicketHelper;
use verbb\events\models\EventType;
use verbb\events\records\EventRecord;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\actions\Delete;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\validators\DateTimeValidator;

use craft\commerce\Plugin as Commerce;

use yii\base\Exception;
use yii\base\InvalidConfigException;

class Event extends Element
{
    // Constants
    // =========================================================================

    const STATUS_LIVE = 'live';
    const STATUS_PENDING = 'pending';
    const STATUS_EXPIRED = 'expired';

    
    // Static
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('events', 'Event');
    }

    public static function refHandle()
    {
        return 'event';
    }

    public static function hasContent(): bool
    {
        return true;
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_LIVE => Craft::t('app', 'Live'),
            self::STATUS_PENDING => Craft::t('app', 'Pending'),
            self::STATUS_EXPIRED => Craft::t('app', 'Expired'),
            self::STATUS_DISABLED => Craft::t('app', 'Disabled')
        ];
    }

    public static function find(): ElementQueryInterface
    {
        return new EventQuery(static::class);
    }

    protected static function defineSources(string $context = null): array
    {
        if ($context == 'index') {
            $eventTypes = Events::$plugin->getEventTypes()->getEditableEventTypes();
            $editable = true;
        } else {
            $eventTypes = Events::$plugin->getEventTypes()->getAllEventTypes();
            $editable = false;
        }

        $eventTypeIds = [];

        foreach ($eventTypes as $eventType) {
            $eventTypeIds[] = $eventType->id;
        }

        $sources = [[
            'key' => '*',
            'label' => Craft::t('events', 'All events'),
            'criteria' => [
                'typeId' => $eventTypeIds,
                'editable' => $editable,
            ],
            'defaultSort' => ['postDate', 'desc'],
        ]];

        $sources[] = ['heading' => Craft::t('events', 'Event Types')];

        foreach ($eventTypes as $eventType) {
            $key = 'eventType:' . $eventType->uid;
            $canEditEvents = Craft::$app->getUser()->checkPermission('events-eventType:' . $eventType->id);

            $sources[] = [
                'key' => $key,
                'label' => $eventType->name,
                'data' => [
                    'handle' => $eventType->handle,
                    'editable' => $canEditEvents,
                ],
                'criteria' => [
                    'typeId' => $eventType->id,
                    'editable' => $editable,
                ]
            ];
        }

        return $sources;
    }

    protected static function defineActions(string $source = null): array
    {
        $actions = [];

        $actions[] = Craft::$app->getElements()->createAction([
            'type' => Delete::class,
            'confirmationMessage' => Craft::t('events', 'Are you sure you want to delete the selected events?'),
            'successMessage' => Craft::t('events', 'Events deleted.'),
        ]);

        return $actions;
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'sku'];
    }


    // Element index methods
    // -------------------------------------------------------------------------

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'startDate' => Craft::t('events', 'Start Date'),
            'endDate' => Craft::t('events', 'End Date'),
            'postDate' => Craft::t('app', 'Post Date'),
            'expiryDate' => Craft::t('app', 'Expiry Date'),
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => ['label' => Craft::t('app', 'Title')],
            'type' => ['label' => Craft::t('app', 'Type')],
            'slug' => ['label' => Craft::t('app', 'Slug')],
            'startDate' => ['label' => Craft::t('events', 'Start Date')],
            'endDate' => ['label' => Craft::t('events', 'End Date')],
            'postDate' => ['label' => Craft::t('app', 'Post Date')],
            'expiryDate' => ['label' => Craft::t('app', 'Expiry Date')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        $attributes = [];

        if ($source === '*') {
            $attributes[] = 'type';
        }

        $attributes[] = 'postDate';
        $attributes[] = 'expiryDate';

        return $attributes;
    }


    // Properties
    // =========================================================================

    public $id;
    public $typeId;
    public $allDay;
    public $capacity;
    public $startDate;
    public $endDate;
    public $postDate;
    public $expiryDate;

    private $_eventType;
    private $_tickets;
    private $_defaultTicket;


    // Public Methods
    // =========================================================================

    public function __toString(): string
    {
        return (string)$this->title;
    }

    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'startDate';
        $attributes[] = 'endDate';
        $attributes[] = 'postDate';
        $attributes[] = 'expiryDate';

        return $attributes;
    }

    public function rules(): array
    {
        $rules = parent::rules();

        $rules[] = [['typeId'], 'number', 'integerOnly' => true];
        $rules[] = [['startDate', 'endDate', 'postDate', 'expiryDate'], DateTimeValidator::class];

        $rules[] = [
            ['tickets'], function($model) {
                foreach ($this->getTickets() as $ticket) {
                    // Break immediately if no ticket type set, also check for other ticket validation errors
                    if (!$ticket->typeId) {
                        $this->addError('tickets', Craft::t('events', 'Ticket type must be set.'));

                        $ticket->addError('typeIds', Craft::t('events', 'Ticket type must be set.'));
                    } else if (!$ticket->validate()) {
                        $this->addError('tickets', Craft::t('events', 'Ticket type must be set.'));
                    }
                }
            }
        ];

        return $rules;
    }

    public function getIsEditable(): bool
    {
        if ($this->getType()) {
            $id = $this->getType()->id;

            return Craft::$app->getUser()->checkPermission('events-manageEventType:' . $id);
        }

        return false;
    }

    public function getCpEditUrl()
    {
        $eventType = $this->getType();

        // The slug *might* not be set if this is a Draft and they've deleted it for whatever reason
        $url = UrlHelper::cpUrl('events/events/' . $eventType->handle . '/' . $this->id . ($this->slug ? '-' . $this->slug : ''));

        if (Craft::$app->getIsMultiSite()) {
            $url .= '/' . $this->getSite()->handle;
        }

        return $url;
    }

    public function getFieldLayout()
    {
        return $this->getType()->getEventFieldLayout();
    }

    public function getUriFormat()
    {
        $eventTypeSiteSettings = $this->getType()->getSiteSettings();

        if (!isset($eventTypeSiteSettings[$this->siteId])) {
            throw new InvalidConfigException('Event’s type (' . $this->getType()->id . ') is not enabled for site ' . $this->siteId);
        }

        return $eventTypeSiteSettings[$this->siteId]->uriFormat;
    }

    public function getSearchKeywords(string $attribute): string
    {
        if ($attribute === 'sku') {
            return implode(' ', ArrayHelper::getColumn($this->getTickets(), 'sku'));
        }

        return parent::getSearchKeywords($attribute);
    }

    public function getType(): EventType
    {
        if ($this->typeId === null) {
            throw new InvalidConfigException('Event is missing its event type ID');
        }

        $eventType = Events::getInstance()->getEventTypes()->getEventTypeById($this->typeId);

        if (null === $eventType) {
            throw new InvalidConfigException('Invalid event type ID: ' . $this->typeId);
        }

        return $eventType;
    }

    public function getSnapshot(): array
    {
        $data = [
            'title' => $this->title
        ];

        return array_merge($this->getAttributes(), $data);
    }

    public function getProduct()
    {
        return $this;
    }

    public function getStatus()
    {
        $status = parent::getStatus();

        if ($status === self::STATUS_ENABLED && $this->postDate) {
            $currentTime = DateTimeHelper::currentTimeStamp();
            $postDate = $this->postDate->getTimestamp();
            $expiryDate = $this->expiryDate ? $this->expiryDate->getTimestamp() : null;

            if ($postDate <= $currentTime && (!$expiryDate || $expiryDate > $currentTime)) {
                return self::STATUS_LIVE;
            }

            if ($postDate > $currentTime) {
                return self::STATUS_PENDING;
            }

            return self::STATUS_EXPIRED;
        }

        return $status;
    }

    public function getTickets(): array
    {
        if (null === $this->_tickets) {
            if ($this->id) {
                $this->setTickets(Events::getInstance()->getTickets()->getAllTicketsByEventId($this->id, $this->siteId));
            }

            // Must have at least one
            if (null === $this->_tickets) {
                $ticket = new Ticket();
                // $ticket->isDefault = true;
                $this->setTickets([$ticket]);
            }
        }

        return $this->_tickets;
    }

    public function setTickets(array $tickets)
    {
        $this->_tickets = [];
        $count = 1;
        $this->_defaultTicket = null;

        if (empty($tickets)) {
            return;
        }

        foreach ($tickets as $key => $ticket) {
            if (!$ticket instanceof Ticket) {
                $ticket = EventHelper::populateEventTicketModel($this, $ticket, $key);
            }

            $ticket->sortOrder = $count++;
            $ticket->setEvent($this);

            $this->_tickets[] = $ticket;
        }
    }

    public function setEagerLoadedElements(string $handle, array $elements)
    {
        if ($handle == 'tickets') {
            $this->setTickets($elements);
        } else {
            parent::setEagerLoadedElements($handle, $elements);
        }
    }

    public static function eagerLoadingMap(array $sourceElements, string $handle)
    {
        if ($handle == 'tickets') {
            $sourceElementIds = ArrayHelper::getColumn($sourceElements, 'id');

            $map = (new Query())
                ->select('eventId as source, id as target')
                ->from(['{{%events_tickets}}'])
                ->where(['in', 'eventId', $sourceElementIds])
                ->orderBy('sortOrder asc')
                ->all();

            return [
                'elementType' => Ticket::class,
                'map' => $map,
            ];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    public static function prepElementQueryForTableAttribute(ElementQueryInterface $elementQuery, string $attribute)
    {
        if ($attribute === 'tickets') {
            $with = $elementQuery->with ?: [];
            $with[] = 'tickets';
            $elementQuery->with = $with;
        } else {
            parent::prepElementQueryForTableAttribute($elementQuery, $attribute);
        }
    }

    public function getIsAvailable()
    {
        return (bool)$this->getAvailableTickets();
    }

    public function getAvailableTickets()
    {
        $tickets = $this->getTickets();

        foreach ($tickets as $key => $ticket) {
            if (!$ticket->getIsAvailable()) {
                unset($tickets[$key]);
            }
        }

        return $tickets;
    }


    // Events
    // -------------------------------------------------------------------------

    public function beforeSave(bool $isNew): bool
    {
        // Make sure the field layout is set correctly
        $this->fieldLayoutId = $this->getType()->fieldLayoutId;

        if ($this->enabled && !$this->postDate) {
            // Default the post date to the current date/time
            $this->postDate = new \DateTime();
            // ...without the seconds
            $this->postDate->setTimestamp($this->postDate->getTimestamp() - ($this->postDate->getTimestamp() % 60));
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew)
    {
        if (!$isNew) {
            $record = EventRecord::findOne($this->id);

            if (!$record) {
                throw new Exception('Invalid event id: ' . $this->id);
            }
        } else {
            $record = new EventRecord();
            $record->id = $this->id;
        }
        
        $record->allDay = $this->allDay;
        $record->capacity = $this->capacity;
        $record->startDate = $this->startDate;
        $record->endDate = $this->endDate;
        $record->postDate = $this->postDate;
        $record->expiryDate = $this->expiryDate;
        $record->typeId = $this->typeId;

        $record->save(false);

        $this->id = $record->id;

        // Only save tickets once (since they will propagate themselves the first time.
        if (!$this->propagating) {
            $keepTicketIds = [];
            $oldTicketIds = (new Query())
                ->select('id')
                ->from('{{%events_tickets}}')
                ->where(['eventId' => $this->id])
                ->column();

            foreach ($this->getTickets() as $ticket) {
                if ($isNew) {
                    $ticket->eventId = $this->id;
                    $ticket->siteId = $this->siteId;
                }

                $keepTicketIds[] = $ticket->id;

                Craft::$app->getElements()->saveElement($ticket, false);
            }

            foreach (array_diff($oldTicketIds, $keepTicketIds) as $deleteId) {
                Craft::$app->getElements()->deleteElementById($deleteId);
            }
        }

        return parent::afterSave($isNew);
    }

    public function beforeRestore(): bool
    {
        $tickets = Ticket::find()->trashed(null)->eventId($this->id)->status(null)->all();
        Craft::$app->getElements()->restoreElements($tickets);
        $this->setTickets($tickets);

        return parent::beforeRestore();
    }

    public function beforeValidate()
    {
        // We need to generate all ticket sku formats before validating the product,
        // since the event validates the uniqueness of all tickets in memory.
        $type = $this->getType();

        foreach ($this->getTickets() as $ticket) {
            if (!$ticket->sku) {
                $ticket->sku = TicketHelper::generateTicketSKU();
            }
        }

        return parent::beforeValidate();
    }

    public function afterDelete()
    {
        $tickets = Ticket::find()
            ->eventId($this->id)
            ->all();

        $elementsService = Craft::$app->getElements();

        foreach ($tickets as $ticket) {
            $ticket->deletedWithEvent = true;
            $elementsService->deleteElement($ticket);
        }

        parent::afterDelete();
    }

    public function afterRestore()
    {
        // Also restore any tickets for this element
        $elementsService = Craft::$app->getElements();

        foreach (ElementHelper::supportedSitesForElement($this) as $siteInfo) {
            $tickets = Ticket::find()
                ->anyStatus()
                ->siteId($siteInfo['siteId'])
                ->eventId($this->id)
                ->trashed()
                ->andWhere(['events_tickets.deletedWithEvent' => true])
                ->all();

            foreach ($tickets as $ticket) {
                $elementsService->restoreElement($ticket);
            }
        }

        $this->setTickets($tickets);

        parent::afterRestore();
    }


    // Protected methods
    // =========================================================================

    protected function route()
    {
        // Make sure the event type is set to have URLs for this site
        $siteId = Craft::$app->getSites()->currentSite->id;
        $eventTypeSiteSettings = $this->getType()->getSiteSettings();

        if (!isset($eventTypeSiteSettings[$siteId]) || !$eventTypeSiteSettings[$siteId]->hasUrls) {
            return null;
        }

        return [
            'templates/render', [
                'template' => $eventTypeSiteSettings[$siteId]->template,
                'variables' => [
                    'event' => $this,
                    'product' => $this,
                ]
            ]
        ];
    }

    protected function tableAttributeHtml(string $attribute): string
    {
        $eventType = $this->getType();

        switch ($attribute) {
            case 'type':
                return ($eventType ? Craft::t('events', $eventType->name) : '');

            default:
                return parent::tableAttributeHtml($attribute);
        }
    }

}