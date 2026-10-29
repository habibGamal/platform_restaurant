<?php

namespace Tests\Feature\App\Management;

use App\Filament\App\Resources\CalendarEventResource\Pages\CreateCalendarEvent;
use App\Filament\App\Resources\CalendarEventResource\Pages\ListCalendarEvents;
use App\Models\CalendarEvent;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class CalendarEventResourceTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private CalendarEvent $orgAEvent;

    private CalendarEvent $globalEvent;

    private CalendarEvent $orgBEvent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->ownerA = User::factory()->forOrganization($this->orgA)->create();
        $this->ownerA->assignRole('org_owner');

        $this->analystA = User::factory()->forOrganization($this->orgA)->create();
        $this->analystA->assignRole('org_analyst');

        $this->orgAEvent = CalendarEvent::create([
            'organization_id' => $this->orgA->id,
            'name' => 'افتتاح فرع جديد',
            'kind' => 'promo',
            'date_from' => '2026-11-01',
            'date_to' => '2026-11-05',
        ]);

        $this->globalEvent = CalendarEvent::create([
            'organization_id' => null,
            'name' => 'عيد الفطر المبارك',
            'kind' => 'eid',
            'date_from' => '2026-03-20',
            'date_to' => '2026-03-23',
        ]);

        $this->orgBEvent = CalendarEvent::create([
            'organization_id' => $this->orgB->id,
            'name' => 'حدث منظمة أخرى',
            'kind' => 'holiday',
            'date_from' => '2026-12-01',
            'date_to' => '2026-12-02',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_list_shows_org_events_and_global_events_not_other_orgs(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(ListCalendarEvents::class)
            ->assertCanSeeTableRecords([$this->orgAEvent, $this->globalEvent])
            ->assertCanNotSeeTableRecords([$this->orgBEvent]);
    }

    public function test_analyst_can_view_but_cannot_create_or_edit_events(): void
    {
        $this->actingAs($this->analystA);
        $this->assertTrue($this->analystA->can('viewAny', CalendarEvent::class));
        $this->assertFalse($this->analystA->can('create', CalendarEvent::class));
        $this->assertFalse($this->analystA->can('update', $this->orgAEvent));
        $this->assertFalse($this->analystA->can('delete', $this->orgAEvent));
    }

    public function test_owner_can_create_event(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(CreateCalendarEvent::class)
            ->fillForm([
                'name' => 'عروض شهر رمضان',
                'kind' => 'ramadan',
                'date_from' => '2026-02-18',
                'date_to' => '2026-03-19',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = CalendarEvent::where('name', 'عروض شهر رمضان')->first();
        $this->assertNotNull($event);
        $this->assertSame($this->orgA->id, $event->organization_id);
        $this->assertSame('ramadan', $event->kind);
    }

    public function test_global_events_are_read_only(): void
    {
        $this->actingAs($this->ownerA);

        // Cannot update or delete global event
        $this->assertFalse($this->ownerA->can('update', $this->globalEvent));
        $this->assertFalse($this->ownerA->can('delete', $this->globalEvent));
    }

    public function test_date_to_must_be_after_or_equal_to_date_from(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(CreateCalendarEvent::class)
            ->fillForm([
                'name' => 'حدث غير صالح',
                'kind' => 'holiday',
                'date_from' => '2026-05-10',
                'date_to' => '2026-05-05',
            ])
            ->call('create')
            ->assertHasFormErrors(['date_to']);
    }
}
