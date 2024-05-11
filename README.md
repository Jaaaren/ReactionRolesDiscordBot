### Prerequisites

- [PHP](https://www.php.net/) 7.4 or later
- [Composer](https://getcomposer.org/) (for managing PHP dependencies)
- [Discord Developer Application](https://discord.com/developers/applications) with a Bot Token
- A Discord server where you can run the bot

### Setting Up the Bot

- Go to the Discord Developer Portal.
- Create a new application and navigate to the "Bot" tab.
- Add a bot, and save the token provided.
- Go to the "OAuth2" tab, select the "bot" and "applications.commands" scopes, and then select the required permissions (Manage Roles, Read Message History and Read Messages/View Channels are mandatory).
- Generate an OAuth2 URL and use it to add the bot to your Discord server.
- The bot can only add/remove a role for users if the role is below its own highest role in Discord's role hierarchy.

### Environment Variables

Add these to your .env file:
DISCORD_BOT_TOKEN=your-bot-token
DISCORD_APPLICATION_ID=your-application-id

### Configure Reaction Roles

Use the /add-reaction-role slash command to begin setting up a reaction role.
Respond with the emoji you want to associate with the role when prompted.
Use the /set-reaction-role slash command to link the selected message to the role.

### Troubleshooting

- ### Bot not responding?
Make sure the bot has the correct permissions in the Discord server and that the bot token is correctly configured.

- ### Type Errors or Dependency Issues?
Make sure PHP and all required dependencies are up-to-date, and that you have installed all the necessary Composer packages.
