# Development

```bash
php bin/phpunit                               # the suite; a few tests need client data
vendor/bin/phpstan analyse                    # level 8, src and tests
vendor/bin/deptrac analyse                    # layers may depend downward only
bin/console lint:container                    # DI must stay green — and APP_ENV=replay too
vendor/bin/php-cs-fixer fix                   # @Symfony ruleset
bin/console anm:snapshot Klemprime            # recapture the replay fixture from a live server
```

The tests that skip read `AreaTable.dbc` and `WorldMapArea.dbc`, which ship with the game client
and cannot be committed. Point `ANM_DBC_DIR` at your server's `data/dbc` in `.env.test.local` and
they run; without it they skip rather than fail, because a missing zone name degrades to the
continent and that is a real, supported state.

Agents are tested with Symfony AI's own `MockAgent`: `anm:talk` runs against the real recorded
world and the real conversation memory, with only the model replaced, so a test says what the
application does with an answer rather than what a model happens to say.

## Structure

Every outbound integration sits behind an interface — `WorldGatewayInterface`,
`GameKnowledgeInterface`, `QuestLogInterface`, `BotStagerInterface`, `OutboxInterface` — which is
what lets the replay implementations be first-class rather than test doubles bolted on
afterwards.

The layers run one way, and `deptrac.yaml` enforces it: World and Config at the bottom, then
Knowledge, Herding, Director, Roleplay, and the two ways in — MCP and the console — on top. Where a
lower layer genuinely needs something from above, it declares an interface and the higher layer
implements it: `Herding\ProtectedBots` is how a companion mid-conversation survives a dismissal
sweep without the crowd layer ever learning what a conversation is.

## Commands

| Command | What it does |
|---|---|
| `anm:listen` | The main loop: answers chat, keeps the zone populated, runs errands and banter |
| `anm:talk` | Talk to a companion from the terminal, as a player in party chat |
| `anm:world` | Who is near a character, and how the zone is populated |
| `anm:prompt` | The system prompt a companion would be given, and where each line came from |
| `anm:ask` | Ask the archivist about a quest, an item, a creature, or where to find something |
| `anm:party` | A player's party, and wake any members who are offline |
| `anm:herd` | Bring a believable crowd into the world around a character |
| `anm:banter` | Have a bot near the player mutter something, now |
| `anm:smoke` | Check every link to the game server end to end, and name the broken one |
| `anm:snapshot` | Capture a slice of the live world into `fixtures/` for replay |
| `mcp:server` | The MCP server, over stdio |
