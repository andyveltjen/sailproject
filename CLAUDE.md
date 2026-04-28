# SAIL Project — Claude instructies

## Deploy-regel (VERPLICHT)

**Voer NOOIT automatisch `./deploy.sh` uit.**

Werkwijze bij elke deploy:
1. Wijzigingen lokaal aanbrengen en uitleggen
2. Altijd expliciet vragen: *"Wil je nu deployen naar de server?"*
3. Wachten op bevestiging van de gebruiker
4. Pas daarna `./deploy.sh` uitvoeren

De gebruiker wil eerst committen naar git en lokaal testen voordat er naar de Combell-server wordt gedeployed.

## Projectoverzicht

- **CMS**: Grav 1.7 (flat-file, geen database)
- **Talen**: NL (standaard) + EN (auto-vertaald via DeepL)
- **Server**: Combell FTP — `ftp.sailprojectai.webhosting.be`
- **Lokaal**: MAMP op `http://localhost:8888/sailproject`
- **Deploy**: `./deploy.sh` (incrementeel, raakt `user/pages/` NOOIT aan)

## Git & Deploy workflow

| Actie | Wanneer | Toestemming nodig? |
|---|---|---|
| `git commit` | Na afronding van een grotere werkende implementatie | Nee, mag automatisch |
| `./deploy.sh` | Alleen na expliciete bevestiging van de gebruiker | **Ja, altijd vragen** |

## Belangrijke regels

- `user/pages/` wordt NOOIT overschreven bij een gewone deploy
- Auto-vertaling: plugin `sail-autotranslate` vertaalt `.nl.md` → `.en.md` bij admin-save
- Wachtwoord admin lokaal: `tijdelijk123`
