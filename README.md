## Meetup2Neo

Simple PHP Script to import informations about a Meetup Event

It will import :

* The Event
* The Event's Group
* The Event's Group's Members
* All Groups Members belong to
* All topics that tags groups
* The RSVPs for the Event

![Imgur](http://i.imgur.com/G01xgHd.png)

![Imgur](http://i.imgur.com/iwzfxAg.png)

After the import it will generate a `meetup.html` file showing the user's topics interests 

![Imgur](http://i.imgur.com/EfbNEuJ.png)

### How to use

Clone this repo

```bash
git clone git@github.com:ikwattro/meetup2neo
```

Install composer (php dependency manager) if you don't have it

```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
```

If you have file permissions problems with the `mv` command, try with `sudo`

Install the dependencies
 
```bash
composer install
```

Modify the config.yml file by adding your neo4j connection informations and your meetup api key

Run the import in the command line and provide an event id :

```bash
php import.php 12345
```

There are two arguments you can pass to the command line, the first one is to skip the creation of the
schema indexes and constraints and the second is to skip the deletion of the database content.

The two arguments are default to `false`.

```bash
php import.php 12345 true true
```

#### Support

If you find a bug please raise a github issue or ping me on twitter