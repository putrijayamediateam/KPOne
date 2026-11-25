<?php

namespace Tests\Feature\DepartmentSpace;

use App\Domain\DepartmentSpace\StationLink;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-22d: the home's station link is decided on the server and only for a page the person may already open.
 */
class StationLinkTest extends VisitTestCase
{
    public function test_a_link_is_given_only_to_someone_who_may_open_the_page(): void
    {
        $ca = $this->actor('ca');
        $this->assertSame('/registration', StationLink::for($ca, 'registration'));
        $this->assertSame('/registration', StationLink::for($ca, 'ma'));
        $this->assertSame('/registration', StationLink::for($ca, 'dispensary'));
        $this->assertNotNull(StationLink::for($this->actor('director'), 'registration'));

        $noAccess = $this->actor('marketing');
        foreach (['registration', 'ma', 'dispensary', 'treatment'] as $station) {
            $this->assertNull(StationLink::for($noAccess, $station), $station);
        }
    }

    public function test_an_unknown_station_gets_no_link(): void
    {
        $this->assertNull(StationLink::for($this->actor('ca'), 'imaging'));
    }
}
