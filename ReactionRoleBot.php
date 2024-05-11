<?php

namespace App\Console\Commands;

/**
 * **********************************************************************
 * __________                      __  .__               
 * \______   \ ____ _____    _____/  |_|__| ____   ____  
 *  |       _// __ \\__  \ _/ ___\   __\  |/  _ \ /    \ 
 *  |    |   \  ___/ / __ \\  \___|  | |  (  <_> )   |  \
 *  |____|_  /\___  >____  /\___  >__| |__|\____/|___|  /
 *         \/     \/     \/     \/                    \/ 
 * __________       .__                                  
 * \______   \ ____ |  |   ____   ______                 
 *  |       _//  _ \|  | _/ __ \ /  ___/                 
 *  |    |   (  <_> )  |_\  ___/ \___ \                  
 *  |____|_  /\____/|____/\___  >____  >                 
 *         \/                 \/     \/  
 * **********************************************************************
 *
 * Features:
 *  - Easy reaction role configuration with /add-reaction-role /set-reaction-role
 *  - Saves reaction role configuration settings in a local reaction_roles.json.
 *  - Automatically adds roles when configured reactions are removed.
 *  - Automatically removes roles when configured reactions are removed.
 *  - Ignores reactions added or removed by bots.
 *
 *
 * Environment Variables:
 * -----------------------
 * The bot assumes these environment variables are set in your .env file:
 * Both of these can be obtained from the Discord Developer Portal.
 * 
 * - DISCORD_BOT_TOKEN: The token for the Discord bot to authenticate API calls.
 * - DISCORD_APPLICATION_ID: The application ID for your Discord application.
 *
 *
 * The bot saves reaction role information in a json file right now. If you plan to 
 * use an SQLite database for file storage, you will probably need:
 *
 * - DB_CONNECTION: Set to 'sqlite'.
 * - DB_DATABASE: Full path to your SQLite database file.
 * - CACHE_STORE: Set to 'database'.
 *
 *
 * Dependencies:
 * -------------------------------------
 * The bot is built using PHP and relies on some composer packages. It was developed
 * in an environment running PHP version 8.2.
 * 
 * These packages were necessary for integration with discord:
 * - "discord-php/http": "^9.0.12" - Handles HTTP requests to the Discord API.
 * - "team-reflex/discord-php": "^7.3" - Main library for interacting with Discord.
 *
 *
 *
 * Created by: Jaren Nicholls
 * **********************************************************************
 */

use Illuminate\Console\Command;
use Discord\Discord;
use Discord\Parts\Channel\Message;
use Discord\Builders\MessageBuilder;
use Discord\WebSockets\Event;
use GuzzleHttp\Client;

class ReactionRoleBot extends Command
{
    /**
     * Command signature used to identify this bot when triggered via Artisan.
     */
    protected $signature = 'run:reaction-role-bot';

    /**
     * Brief description of the bot command to show in Artisan.
     */
    protected $description = 'Runs the Reaction Role Bot';

    /**
     * Stores all reaction-role configurations in memory for quick reference.
     * Each key is a message ID, and each value is an array mapping emojis to role IDs.
     */
    private array $reactionRoleMappings = [];

    /**
     * Path to the JSON file used for persisting the reaction-role mappings.
     */
    private string $reactionRoleStoragePath = __DIR__ . '/reaction_roles.json';

    /**
     * Keeps track of messages that are awaiting reaction assignments.
     * Each key is a message ID, and each value is a role ID awaiting an associated emoji.
     */
    private array $pendingReactions = [];

    /**
     * Primary entry point of the bot, called by the Artisan console.
     */
    public function handle()
    {
        // Initialize Discord instance with bot token from environment variables
        $discord = new Discord(['token' => env('DISCORD_BOT_TOKEN')]);

        // Listen for events when the bot is ready
        $discord->on('ready', function (Discord $discord) {
            // Load existing reaction-role mappings from persistent storage
            $this->loadReactionRoles();

            // Register slash commands with Discord's API
            $this->registerSlashCommands(env('DISCORD_BOT_TOKEN'), env('DISCORD_APPLICATION_ID'));

            echo "Bot is ready and listening for reactions." . PHP_EOL;

            // Event listener for adding reactions
            $discord->on(Event::MESSAGE_REACTION_ADD, function ($reaction) {
                $this->processReactionAdd($reaction);
            });

            // Event listener for removing reactions
            $discord->on(Event::MESSAGE_REACTION_REMOVE, function ($reaction) {
                $this->processReactionRemove($reaction);
            });

            // Event listener for interactions (e.g., slash commands)
            $discord->on(Event::INTERACTION_CREATE, function ($interaction) {
                $command = $interaction->data->name;

                // Handle different commands and direct them to their appropriate methods
                switch ($command) {
                    case 'add-reaction-role':
                        $this->initializeReactionRole($interaction);
                        break;
                    case 'set-reaction-role':
                        $this->assignReactionRole($interaction);
                        break;
                }
            });
        });

        // Run the Discord event loop
        $discord->run();
    }

    /**
     * Load reaction-role mappings from the JSON file if it exists.
     */
    private function loadReactionRoles()
    {
        if (file_exists($this->reactionRoleStoragePath)) {
            $this->reactionRoleMappings = json_decode(file_get_contents($this->reactionRoleStoragePath), true) ?? [];
        }
    }

    /**
     * Save the current reaction-role mappings to the JSON file.
     */
    private function saveReactionRoles()
    {
        file_put_contents($this->reactionRoleStoragePath, json_encode($this->reactionRoleMappings));
    }

    /**
     * Process a newly added reaction and assign the corresponding role.
     * 
     * @param \Discord\Parts\Channel\Message\Reaction $reaction
     */
    private function processReactionAdd($reaction)
    {
        // Ignore reactions added by other bots
        if ($reaction->user->bot) {
            return;
        }

        $messageId = $reaction->message_id;
        $emoji = $reaction->emoji->name;
        $userId = $reaction->user_id;

        // If the message is awaiting an emoji assignment, associate the reaction with a role
        if (isset($this->pendingReactions[$messageId])) {
            $roleId = $this->pendingReactions[$messageId];
            $this->reactionRoleMappings[$roleId] = $emoji;
            $this->saveReactionRoles();

            // Send a confirmation message and clear pending state
            $reaction->channel->sendMessage("Emoji {$emoji} has been set for role ID {$roleId}. Use /set-reaction-role to finalize setup.");
            unset($this->pendingReactions[$messageId]);
            return;
        }

        // Assign the role to the user if the reaction matches a configured emoji
        if (isset($this->reactionRoleMappings[$messageId])) {
            foreach ($this->reactionRoleMappings[$messageId] as $mapping) {
                $mappedEmoji = $mapping['emoji'];
                $roleId = $mapping['roleID'];

                if ($emoji === $mappedEmoji) {
                    // Prepare the request URL and authentication headers for role assignment
                    $guildId = $reaction->channel->guild->id;
                    $url = "https://discord.com/api/v9/guilds/{$guildId}/members/{$userId}/roles/{$roleId}";
                    $token = getenv('DISCORD_BOT_TOKEN');
                    $client = new Client();

                    // Assign the role via Discord's API
                    try {
                        $response = $client->put($url, [
                            'headers' => [
                                'Authorization' => "Bot {$token}",
                                'Content-Type' => 'application/json',
                            ]
                        ]);

                        if ($response->getStatusCode() == 204) {
                            error_log("Assigned role ID {$roleId} to user ID {$userId} successfully.");
                        } else {
                            error_log("Failed to assign role. Status code: " . $response->getStatusCode());
                        }
                    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                        error_log("API role assignment error: {$e->getMessage()}");
                    }

                    break;  // Stop further checks once a match is found
                }
            }
        }
    }
    
    /**
     * Handles the removal of reactions and ensures the appropriate role is revoked.
     *
     * @param \Discord\Parts\Channel\Message\Reaction $reaction
     */
    private function processReactionRemove($reaction)
    {
        // Ignore reactions removed by other bots
        if ($reaction->user->bot) {
            return;
        }

        $messageId = $reaction->message_id;
        $emoji = $reaction->emoji->name;
        $userId = $reaction->user_id;

        // Check if the message has configured reaction-role pairs
        if (isset($this->reactionRoleMappings[$messageId])) {
            // Loop through the emoji-role pairs and find the matching configuration
            foreach ($this->reactionRoleMappings[$messageId] as $mapping) {
                $mappedEmoji = $mapping['emoji'];
                $roleId = $mapping['roleID'];

                // If the removed emoji matches the configured one, proceed to revoke the role
                if ($emoji === $mappedEmoji) {
                    // Prepare the URL for the role removal API call
                    $guildId = $reaction->channel->guild->id;
                    $url = "https://discord.com/api/v9/guilds/{$guildId}/members/{$userId}/roles/{$roleId}";
                    $token = getenv('DISCORD_BOT_TOKEN');
                    $client = new Client();

                    // Send a DELETE request to remove the role
                    try {
                        $response = $client->delete($url, [
                            'headers' => [
                                'Authorization' => "Bot {$token}",
                                'Content-Type' => 'application/json'
                            ]
                        ]);

                        if ($response->getStatusCode() == 204) {
                            error_log("Successfully removed role ID {$roleId} from user ID {$userId}");
                        } else {
                            error_log("Failed to remove role. Status code: " . $response->getStatusCode());
                        }
                    } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                        error_log("Failed to remove role via API: {$e->getMessage()}");
                    }

                    break;  // Stop further checks after finding the correct emoji-role pair
                }
            }
        } else {
            error_log("No reaction role configuration found for message ID {$messageId}");
        }
    }

    /**
     * Handles the addition of a new reaction role by prompting the user to react with an emoji.
     *
     * @param \Discord\Parts\Interactions\Interaction $interaction
     */
    private function initializeReactionRole($interaction)
    {
        // Ensure a valid role is specified in the interaction options
        if (empty($interaction->data->options['role']) || !isset($interaction->data->options['role']['value'])) {
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("Error: No role specified. Please provide a role."), true);
            return;
        }

        // Extract the role ID from the options provided in the interaction
        $roleId = $interaction->data->options['role']['value'];

        // Send a message prompting the user to react with an emoji
        $interaction->channel->sendMessage(MessageBuilder::new()->setContent("React to this message with the emoji you want to associate with the role."))
            ->then(function (Message $message) use ($roleId) {
                // Store the message ID and associate it with the provided role ID
                $this->pendingReactions[$message->id] = $roleId;
                error_log("Awaiting reaction on message ID: {$message->id} for role ID: {$roleId}");
            });

        // Confirm that the bot is awaiting an emoji reaction
        $interaction->respondWithMessage(MessageBuilder::new()->setContent("Please react to the above message with the emoji for the role."), true);
    }

    /**
     * Finalizes the reaction-role association for a given message by ensuring the emoji is configured.
     *
     * @param \Discord\Parts\Interactions\Interaction $interaction
     */
    private function assignReactionRole($interaction)
    {
        // Extract options from the interaction and organize them into a convenient associative array
        $options = [];
        foreach ($interaction->data->options as $option) {
            $options[$option->name] = $option->value;
        }

        // Ensure both message ID and role parameters are provided
        if (!isset($options['message_id']) || !isset($options['role'])) {
            $interaction->channel->sendMessage("Error: Missing required parameters. Please provide both a message ID and a role.");
            return;
        }

        $messageId = $options['message_id'];
        $roleId = $options['role'];

        // Check if a reaction-role setup exists for the provided role ID
        if (isset($this->reactionRoleMappings[$roleId])) {
            // Get the associated emoji for this role ID
            $emoji = $this->reactionRoleMappings[$roleId];

            // Ensure an array exists for multiple configurations tied to this message ID
            if (!isset($this->reactionRoleMappings[$messageId])) {
                $this->reactionRoleMappings[$messageId] = [];
            }

            // Add the new emoji-role pair to this message ID's configuration
            $this->reactionRoleMappings[$messageId][] = ['emoji' => $emoji, 'roleID' => $roleId];
            $this->saveReactionRoles();

            // Fetch the message and react with the emoji to confirm the setup
            $interaction->channel->messages->fetch($messageId)->then(function ($message) use ($emoji) {
                $message->react($emoji)->done(
                    function () {
                        error_log("Bot reacted with {$emoji} to the message.");
                    },
                    function ($error) {
                        error_log("Failed to react to the message: {$error->getMessage()}");
                    }
                );
            });

            // Respond to confirm the successful setup
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("Reaction role setup completed and reacted to the message."), true);
        } else {
            // Respond with an error if no matching setup is found
            $interaction->respondWithMessage(MessageBuilder::new()->setContent("No setup found or mismatch in role ID."), true);
        }
    }

    /**
     * Registers the slash commands needed for this bot with the Discord API.
     *
     * @param string $token        Discord bot token for authentication
     * @param string $applicationId Application ID associated with this bot
     */
    private function registerSlashCommands($token, $applicationId)
    {
        // URL for registering application commands
        $url = "https://discord.com/api/v9/applications/{$applicationId}/commands";
        $client = new Client();

        // Define the bot's slash commands and their options
        $commands = [
            [
                'name' => 'add-reaction-role',
                'description' => 'Initiates the process of setting up a reaction role',
                'options' => [
                    [
                        'type' => 8,  // ROLE option type
                        'name' => 'role',
                        'description' => 'The role to pair with a reaction',
                        'required' => true
                    ],
                ]
            ],
            [
                'name' => 'set-reaction-role',
                'description' => 'Associates a reaction role with a specific message',
                'options' => [
                    [
                        'type' => 3,  // STRING option type for message ID
                        'name' => 'message_id',
                        'description' => 'The ID of the message to add the reaction to',
                        'required' => true,
                    ],
                    [
                        'type' => 8,  // ROLE option type
                        'name' => 'role',
                        'description' => 'The role to pair with a reaction for this message',
                        'required' => true,
                    ],
                ],
            ],
        ];

        // Register each command via the Discord API
        foreach ($commands as $command) {
            try {
                $response = $client->post($url, [
                    'headers' => [
                        'Authorization' => 'Bot ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $command,
                ]);

                // Confirm successful registration or provide an error message
                if (in_array($response->getStatusCode(), [200, 201])) {
                    echo 'Slash command registered: ' . $command['name'] . PHP_EOL;
                } else {
                    echo 'Error registering command: ' . $response->getBody() . PHP_EOL;
                }
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                echo 'Request failed: ' . $e->getMessage() . PHP_EOL;
            }
        }
    }
}
