<?php 

namespace Tests\Unit;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use DTApi\Repository\UserRepository;
use DTApi\Models\User;
use Mockery;

class UserRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected $userRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userRepo = new UserRepository(new User);
    }

    public function testCreateNewUserWithCustomerRole()
    {
        $requestData = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '1234567890',
            'consumer_type' => 'paid',
            'company_id' => '',
            'department_id' => '',
            'username' => 'johndoe',
            'address' => '123 Main St',
            'city' => 'City',
            'town' => 'Town',
            'country' => 'Country',
            'reference' => 'yes',
            // other fields
        ];

        $user = $this->userRepo->createOrUpdate(null, $requestData);

        // Assert user creation
        $this->assertNotNull($user);
        $this->assertEquals($user->name, 'John Doe');
        $this->assertEquals($user->userMeta->consumer_type, 'paid');

        // Assert company and department creation when consumer_type is 'paid'
        $this->assertNotNull($user->company);
        $this->assertNotNull($user->department);
    }

    public function testUpdateExistingUserWithTranslatorRole()
    {
        // Assuming an existing user
        $user = factory(User::class)->create();
        $userMeta = $user->userMeta;

        $requestData = [
            'role' => env('TRANSLATOR_ROLE_ID'),
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '0987654321',
            'translator_type' => 'freelance',
            'worked_for' => 'yes',
            'organization_number' => '1234567890',
            'gender' => 'female',
            'translator_level' => 'expert',
            // other fields
        ];

        $updatedUser = $this->userRepo->createOrUpdate($user->id, $requestData);

        // Assert that the user details were updated
        $this->assertEquals($updatedUser->name, 'Jane Doe');
        $this->assertEquals($updatedUser->userMeta->translator_type, 'freelance');
        $this->assertEquals($updatedUser->userMeta->translator_level, 'expert');
    }

    public function testAddToBlacklistForCustomerRole()
    {
        $requestData = [
            'role' => env('CUSTOMER_ROLE_ID'),
            'name' => 'Jack Doe',
            'email' => 'jack.doe@example.com',
            'translator_ex' => [1, 2], // Example translator IDs
            // other fields
        ];

        $user = $this->userRepo->createOrUpdate(null, $requestData);

        // Assert user added to blacklist
        $this->assertDatabaseHas('users_blacklist', [
            'user_id' => $user->id,
            'translator_id' => 1,
        ]);
        $this->assertDatabaseHas('users_blacklist', [
            'user_id' => $user->id,
            'translator_id' => 2,
        ]);
    }

    public function testDisableUser()
    {
        $user = factory(User::class)->create();

        $this->userRepo->disable($user->id);

        $user->refresh();
        $this->assertEquals($user->status, '0');
    }

    public function testEnableUser()
    {
        $user = factory(User::class)->create();
        $this->userRepo->enable($user->id);

        $user->refresh();
        $this->assertEquals($user->status, '1');
    }
}
