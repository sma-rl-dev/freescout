<?php

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TesterEnvSeeder extends Seeder
{
    public function run()
    {
        Model::unguard();

        DB::transaction(function () {
            $this->clearMutableData();
            $admin = $this->prepareAdminUser();
            $agents = $this->createAgents();
            $mailboxes = $this->createMailboxes();
            $customers = $this->createCustomers();

            $this->assignMailboxAccess($admin, $agents, $mailboxes);

            $this->createConversations($mailboxes, $customers, $agents);

            foreach ($mailboxes as $mailbox) {
                $mailbox->updateFoldersCounters();
            }
        });

        Model::reguard();
    }

    private function clearMutableData()
    {
        foreach ([
            'conversation_folder',
            'followers',
            'subscriptions',
            'notifications',
            'attachments',
            'send_logs',
            'threads',
            'conversations',
            'emails',
            'customer_channel',
            'customers',
            'folders',
            'mailbox_user',
            'mailboxes',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        DB::table('users')->where('email', '<>', 'admin@tester-env.local')->delete();
    }

    private function prepareAdminUser()
    {
        $admin = User::where('email', 'admin@tester-env.local')->firstOrFail();
        $admin->forceFill([
            'first_name' => 'Alex',
            'last_name' => 'Admin',
            'role' => User::ROLE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'job_title' => 'Support Operations Lead',
            'timezone' => 'UTC',
            'time_format' => User::TIME_FORMAT_24,
            'invite_state' => User::INVITE_STATE_ACTIVATED,
            'updated_at' => '2026-06-01 09:00:00',
        ])->save();

        return $admin;
    }

    private function createAgents()
    {
        $rows = [
            'mia' => ['Mia', 'Chen', 'mia.chen@tester-env.local', 'Escalation Specialist', [User::PERM_DELETE_CONVERSATIONS => 1]],
            'noah' => ['Noah', 'Patel', 'noah.patel@tester-env.local', 'Returns Coordinator', [User::PERM_DELETE_CONVERSATIONS => 1, User::PERM_ONLY_ASSIGNED_TICKETS => 1]],
            'olivia' => ['Olivia', 'Reed', 'olivia.reed@tester-env.local', 'Billing Analyst', []],
        ];

        $agents = [];
        foreach ($rows as $key => $row) {
            $agents[$key] = User::create([
                'first_name' => $row[0],
                'last_name' => $row[1],
                'email' => $row[2],
                'password' => 'TesterEnv123!',
                'role' => User::ROLE_USER,
                'status' => User::STATUS_ACTIVE,
                'job_title' => $row[3],
                'timezone' => 'UTC',
                'time_format' => User::TIME_FORMAT_24,
                'invite_state' => User::INVITE_STATE_ACTIVATED,
                'created_at' => '2026-06-01 09:00:00',
                'updated_at' => '2026-06-01 09:00:00',
            ]);
            DB::table('users')->where('id', $agents[$key]->id)->update([
                'permissions' => $row[4] ? json_encode($row[4]) : null,
            ]);
        }

        return $agents;
    }

    private function createMailboxes()
    {
        $definitions = [
            'support' => ['Northwind Support', 'support@tester-env.local', 'Customer help desk for Northwind Outdoor Gear.'],
            'billing' => ['Northwind Billing', 'billing@tester-env.local', 'Invoices, refunds, and payment changes.'],
            'returns' => ['Northwind Returns', 'returns@tester-env.local', 'Returns desk for warranty and exchange requests.'],
        ];

        $mailboxes = [];
        foreach ($definitions as $key => $definition) {
            $mailboxes[$key] = Mailbox::create([
                'name' => $definition[0],
                'email' => $definition[1],
                'aliases' => null,
                'from_name' => Mailbox::FROM_NAME_MAILBOX,
                'ticket_status' => Mailbox::TICKET_STATUS_PENDING,
                'ticket_assignee' => Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED,
                'signature' => '<br><span style="color:#808080;">--<br>'.$definition[2].'</span>',
                'created_at' => '2026-06-01 09:05:00',
                'updated_at' => '2026-06-01 09:05:00',
            ]);
        }

        return $mailboxes;
    }

    private function assignMailboxAccess($admin, $agents, $mailboxes)
    {
        $assignments = [
            'support' => [
                $admin->id => [],
                $agents['mia']->id => ['access' => json_encode([Mailbox::ACCESS_PERM_SIGNATURE])],
                $agents['noah']->id => [],
            ],
            'returns' => [
                $admin->id => [],
                $agents['noah']->id => [],
            ],
            'billing' => [
                $admin->id => [],
                $agents['olivia']->id => [],
            ],
        ];

        foreach ($assignments as $mailboxKey => $users) {
            $mailboxes[$mailboxKey]->users()->sync($users);
            $mailboxes[$mailboxKey]->syncPersonalFolders(array_keys($users));
        }
    }

    private function createCustomers()
    {
        $rows = [
            'avery' => ['Avery', 'Stone', 'Summit Cycles', 'Operations Manager', 'avery.stone@summit-cycles.example', 'Needs weekend shipping coverage for retail launch.'],
            'jamie' => ['Jamie', 'Ortiz', 'Harbor Cafe Group', 'Owner', 'jamie.ortiz@harbor-cafe.example', 'Billing contact for three storefronts.'],
            'taylor' => ['Taylor', 'Nguyen', 'Ridgeline Outfitters', 'Purchasing Lead', 'taylor.nguyen@ridgeline.example', 'Often requests warranty replacements for trail equipment.'],
            'priya' => ['Priya', 'Shah', 'Cedar Schools', 'IT Coordinator', 'priya.shah@cedar-schools.example', 'Uses purchase orders and needs invoice PDFs.'],
            'marco' => ['Marco', 'Diaz', 'Blue Peak Rentals', 'Fleet Manager', 'marco.diaz@blue-peak.example', 'Tracks repair turnaround for rental inventory.'],
            'sophia' => ['Sophia', 'Klein', 'Brightpath Logistics', 'Account Director', 'sophia.klein@brightpath.example', 'Executive escalation contact for delayed freight.'],
        ];

        $customers = [];
        foreach ($rows as $key => $row) {
            $customerId = DB::table('customers')->insertGetId([
                'first_name' => $row[0],
                'last_name' => $row[1],
                'company' => $row[2],
                'job_title' => $row[3],
                'notes' => $row[5],
                'city' => 'Portland',
                'state' => 'OR',
                'country' => 'US',
                'created_at' => '2026-06-01 09:10:00',
                'updated_at' => '2026-06-01 09:10:00',
            ]);
            DB::table('emails')->insert([
                'customer_id' => $customerId,
                'email' => $row[4],
                'type' => 1,
            ]);
            $customers[$key] = (object) ['id' => $customerId, 'email' => $row[4], 'name' => $row[0].' '.$row[1]];
        }

        return $customers;
    }

    private function createConversations($mailboxes, $customers, $agents)
    {
        $definitions = [
            ['support', 'avery', 'mia', Conversation::STATUS_ACTIVE, 'Expedite replacement tent poles before Friday', 'Customer needs replacement tent poles for the Summit Cycles demo booth before the Friday trail expo.', '2026-06-10 14:20:00'],
            ['billing', 'jamie', 'olivia', Conversation::STATUS_PENDING, 'Update billing contact for Harbor Cafe Group', 'Please move all renewal notices to finance@harbor-cafe.example after the June invoice closes.', '2026-06-08 16:45:00'],
            ['support', 'sophia', null, Conversation::STATUS_ACTIVE, 'Freight delay on showroom order NW-1048', 'Brightpath Logistics reports the showroom order is stalled at the regional freight terminal.', '2026-06-09 10:15:00'],
            ['billing', 'priya', null, Conversation::STATUS_CLOSED, 'Paid invoice INV-2026-041 needs receipt', 'Cedar Schools requested a receipt for paid invoice INV-2026-041; receipt was sent and ticket closed.', '2026-05-29 11:30:00'],
            ['returns', 'taylor', 'noah', Conversation::STATUS_ACTIVE, 'Warranty exchange for RidgeLine GPS beacon', 'Warranty exchange requested for GPS beacon serial RL-7781 after battery compartment failure.', '2026-06-07 09:05:00'],
            ['returns', 'marco', null, Conversation::STATUS_PENDING, 'Return label for damaged rental stove kit', 'Blue Peak Rentals needs a prepaid return label for stove kit SKU STOVE-24 with cracked burner housing.', '2026-06-05 13:00:00'],
            ['support', 'priya', 'mia', Conversation::STATUS_SPAM, 'Unrelated vendor newsletter', 'Marketing newsletter misrouted into support and marked as spam for filter testing.', '2026-06-03 08:00:00'],
            ['returns', 'avery', 'noah', Conversation::STATUS_CLOSED, 'Replacement buckle arrived successfully', 'Avery confirmed the replacement buckle arrived and the case can remain closed.', '2026-05-25 15:40:00'],
        ];

        $number = 7001;
        foreach ($definitions as $definition) {
            list($mailboxKey, $customerKey, $agentKey, $status, $subject, $body, $createdAt) = $definition;
            $mailbox = $mailboxes[$mailboxKey];
            $customer = $customers[$customerKey];
            $agent = $agentKey ? $agents[$agentKey] : null;

            $conversation = new Conversation([
                'number' => $number,
                'threads_count' => 1,
                'type' => Conversation::TYPE_EMAIL,
                'status' => $status,
                'state' => Conversation::STATE_PUBLISHED,
                'subject' => $subject,
                'customer_email' => $customer->email,
                'preview' => $body,
                'imported' => true,
                'mailbox_id' => $mailbox->id,
                'user_id' => $agent ? $agent->id : null,
                'customer_id' => $customer->id,
                'created_by_customer_id' => $customer->id,
                'source_via' => Conversation::PERSON_CUSTOMER,
                'source_type' => Conversation::SOURCE_TYPE_EMAIL,
                'closed_by_user_id' => $status === Conversation::STATUS_CLOSED ? $agents['mia']->id : null,
                'closed_at' => $status === Conversation::STATUS_CLOSED ? $createdAt : null,
                'last_reply_at' => $createdAt,
                'last_reply_from' => Conversation::PERSON_CUSTOMER,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $conversation->updateFolder($mailbox);
            $conversation->save();

            $thread = new Thread([
                'conversation_id' => $conversation->id,
                'user_id' => $agent ? $agent->id : null,
                'type' => Thread::TYPE_CUSTOMER,
                'status' => $status,
                'state' => Thread::STATE_PUBLISHED,
                'body' => '<p>'.$body.'</p>',
                'from' => $customer->email,
                'to' => json_encode([$mailbox->email]),
                'message_id' => '<tester-env-freescout-'.$number.'@tester-env.local>',
                'source_via' => Thread::PERSON_CUSTOMER,
                'source_type' => Thread::SOURCE_TYPE_EMAIL,
                'customer_id' => $customer->id,
                'created_by_customer_id' => $customer->id,
                'first' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $thread->save();

            DB::table('conversations')->where('id', $conversation->id)->update([
                'number' => $number,
                'updated_at' => $createdAt,
            ]);

            if ($number === 7001) {
                $starred = Folder::where('mailbox_id', $mailbox->id)
                    ->where('user_id', User::where('email', 'admin@tester-env.local')->value('id'))
                    ->where('type', Folder::TYPE_STARRED)
                    ->first();
                if ($starred) {
                    DB::table('conversation_folder')->insert([
                        'folder_id' => $starred->id,
                        'conversation_id' => $conversation->id,
                    ]);
                }
            }

            $number++;
        }
    }
}
