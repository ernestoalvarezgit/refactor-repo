<?php 

namespace Tests\Unit;

use Tests\TestCase;
use Carbon\Carbon;
use DTApi\Helpers\TeHelper;

class TeHelperTest extends TestCase
{
    public function testWillExpireAtLessThan90Hours()
    {
        $createdAt = Carbon::now();
        $dueTime = $createdAt->addHours(85); // Less than 90 hours difference
        $result = TeHelper::willExpireAt($dueTime, $createdAt);
        
        $this->assertEquals($dueTime->format('Y-m-d H:i:s'), $result);
    }

    public function testWillExpireAtMoreThan90HoursLessThan24Hours()
    {
        $createdAt = Carbon::now();
        $dueTime = $createdAt->addHours(120); // More than 90 but less than 24 hours difference
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($createdAt->addMinutes(90)->format('Y-m-d H:i:s'), $result);
    }

    public function testWillExpireAtMoreThan24HoursLessThan72Hours()
    {
        $createdAt = Carbon::now();
        $dueTime = $createdAt->addHours(48); // Between 24 and 72 hours difference
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($createdAt->addHours(16)->format('Y-m-d H:i:s'), $result);
    }

    public function testWillExpireAtMoreThan72Hours()
    {
        $createdAt = Carbon::now();
        $dueTime = $createdAt->addHours(100); // More than 72 hours difference
        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($dueTime->subHours(48)->format('Y-m-d H:i:s'), $result);
    }
}
