# The MCP tools

The whole world, exposed to any assistant that speaks the Model Context Protocol, through
Symfony's MCP Bundle. Each tool is a method with `#[McpTool]` on a service in `src/Mcp`, picked
up by the bundle, so there is no list to keep in step.

Twenty-two, split by whether they change anything. A client may run a read-only tool without
asking permission, so the split — carried in each tool's `ToolAnnotations` — is a permission
boundary rather than tidiness.

| Tool | What it answers | Changes the world |
|---|---|---|
| `world_player_state` | Where a character is, their party, their quest log with progress | no |
| `world_nearby_actors` | Who is around them, and which creatures are *currently alive* | no |
| `world_zone_population` | Where the world is busy and where it is empty | no |
| `look_up_quest` | A quest on **this** server, every step when a chain reuses a title | no |
| `look_up_item` | Quality, item level, required level | no |
| `where_to_get` | Which creatures drop it, or which chest holds it, and where | no |
| `where_is` | Where a named person or creature stands | no |
| `find_creature` | What these things are, how tough, and roughly where | no |
| `what_needs_item` | Which quests want the thing in your bag | no |
| `recent_chat` | What the world has been saying | no |
| `prepare_companion` | Set a specific character aside to play with later | **yes** |
| `planned_companions` | Who is waiting for a character | no |
| `cancel_companion` | Stop keeping one aside | **yes** |
| `summon_crowd` | Populate a zone with level-appropriate bots | **yes** |
| `send_bots_away` | Thin a crowd without emptying it | **yes** |
| `bot_command` | Give one companion an order | **yes** |
| `what_to_do_next` | What a character could get on with: ready to hand in, still needed, what else the zone offers | no |
| `world_settings` | How the director is currently set up | no |
| `configure_world` | Crowd size, level range, how talkative companions are | **yes** |
| `set_herding` | Turn the crowd on or off | **yes** |
| `follow_character` | Whose zone to keep alive | **yes** |
| `stop_following_character` | Stop following one | **yes** |

**There is no cockpit, and there does not need to be.** Everything you would reach for a
dashboard to change — how busy the world is, whose zone it follows, whether it is herding at
all — is a sentence to an assistant, applied on the listener's next pass with no restart. That is
the part MCP makes possible that a bespoke UI would only have imitated.

**Safety.** `bot_command` reaches a GM console, so it is validated twice: the verb against the
mod's own command vocabulary, and the name against the shape a character name can have. Only the
stdio transport is on. The HTTP transport has no authentication of its own, and nine of these
tools change a running world — bound to a local port, any process on the machine, or any page
that can reach localhost, could order bots about.

Setting it up in Claude Desktop, against the recording or a live server: [try-it.md](try-it.md).
