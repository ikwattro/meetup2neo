<?php

require_once(__DIR__.'/vendor/autoload.php');

use DMS\Service\Meetup\MeetupKeyAuthClient;
use Symfony\Component\Yaml\Yaml;
use Neoxygen\NeoClient\ClientBuilder;

$skipSchemaSetup = false;
$dropDbOnInit = false;
if (!isset($argv[1]) || empty($argv[1])) {
    throw new \InvalidArgumentException('You need to pass the event ID as argument : php import.php 12345678');
}
if (isset($argv[2]) && true == (bool) $argv[2]) {
    $skipSchemaSetup = true;
}
if (isset($argv[3]) && (bool) $argv[3] == true) {
    $dropDbOnInit = true;
}
$eventId = (int) $argv[1];
$config = YAML::parse(file_get_contents(__DIR__.'/config.yml'));

$meetupClient = MeetupKeyAuthClient::factory(array('key' => $config['meetup_api_key']));
$neoClient = ClientBuilder::create()
    ->addConnection(
        'default',
        $config['neo4j_scheme'],
        $config['neo4j_host'],
        $config['neo4j_port'],
        true,
        $config['neo4j_user'],
        $config['neo4j_password']
    )
    ->setAutoFormatResponse(true)
    ->build();

// Creating Schema Indexes And Constraints
if (!$skipSchemaSetup) {
    $neoClient->createUniqueConstraint('Event', 'id');
    $neoClient->createUniqueConstraint('Member', 'id');
    $neoClient->createUniqueConstraint('Topic', 'id');
    $neoClient->createUniqueConstraint('Country', 'code');
    $neoClient->createIndex('City', 'name');
} else {
    echo 'Skipping Schema Creation' . "\n";
}

if ($dropDbOnInit) {
    echo 'Dropping DB' . "\n";
    $neoClient->sendCypherQuery('MATCH (n) OPTIONAL MATCH (n)-[r]-() DELETE r,n');
}

// Get Event Informations
$event = $meetupClient->getEvent(array('id' => $eventId));
$eventName = $event['name'];
$eventDesc = $event['description'];
$eventUrl = $event['event_url'];
$groupUrl = $event['group']['urlname'];

// Inserting Event Into Neo4j

$query = 'MERGE (event:Event {id: {event_id}})
ON CREATE SET event.name = {event_name}, event.description = {event_desc}, event.url = {event_url}';
$p = [
    'event_id' => $eventId,
    'event_name' => $eventName,
    'event_desc' => $eventDesc,
    'event_url' => $eventUrl
];
$neoClient->sendCypherQuery($query, $p);


// Get Meetup Group Informations
$groups = $meetupClient->getGroups(array('group_urlname' => $groupUrl));
$group = $groups->current();
$groupId = $group['id'];
$groupName = $group['name'];
$groupDesc = $group['description'];
$groupCountry = strtoupper($group['country']);
$groupTZ = $group['timezone'];
$groupCity = ucfirst($group['city']);
$groupTopics = $group['topics'];
$groupOrganizer = $group['organizer'];

// Inserting Meetup Group Informations

$query = 'MATCH (event:Event {id: {event_id}})
MERGE (g:Group {id: {group_id}})
ON CREATE SET g.name = {group_name}, g.description = {group_desc}, g.url = {group_url}
MERGE (g)-[:ORGANISE_EVENT]->(event)
MERGE (country:Country {code: {country}})
MERGE (city:City {name: {city}})
MERGE (g)-[:GROUP_IN_CITY]->(city)-[:IN_COUNTRY]->(country)
WITH g
UNWIND {topics} as topic
MERGE (t:Topic {id: topic.id})
ON CREATE SET t.name = topic.name
MERGE (t)-[:TAGS_GROUP]->(g)';
$p = [
    'event_id' => $eventId,
    'group_id' => $groupId,
    'group_name' => $groupName,
    'group_desc' => $groupDesc,
    'group_url' => $groupUrl,
    'country' => $groupCountry,
    'city' => $groupCity,
    'topics' => $groupTopics
];
$neoClient->sendCypherQuery($query, $p);

// Inserting Meetup Group's Organiser

$query = 'MATCH (group:Group {id: {group_id}})
MERGE (m:Member {id: {member_id}})
ON CREATE SET m.name = {member_name}
MERGE (m)-[:ORGANISE_GROUP]->(group)';
$p = [
    'member_id' => $groupOrganizer['member_id'],
    'member_name' => $groupOrganizer['name'],
    'group_id' => $groupId
];
$neoClient->sendCypherQuery($query, $p);

// Get Group's Members
$groupMembers = [];
$members = $meetupClient->getMembers(array('group_id' => $groupId));
foreach ($members as $member) {
    $m = [
        'id' => (int) $member['id'],
        'name' => $member['name'],
        'country' => strtoupper($member['country']),
        'city' => ucfirst($member['city']),
        'topics' => $member['topics'],
        'joined_time' => $member['joined'],
        'avatar' => isset($member['photo']['thumb_link']) ?: null
    ];
    $groupMembers[$m['id']] = $m;
}

// Get Member's Groups

foreach ($members as $member) {
    $mgroups = $meetupClient->getGroups(array('member_id' => $member['id']));
    foreach ($mgroups as $g) {
        $groupMembers[$member['id']]['groups'][] = $g;
    }
    usleep(50000);
}

// Inserting Group's Members and Groups they belong to
foreach ($members as $member) {
    //Inserting the member
    echo 'Inserting Member "' . $member['name'] . '"' . "\n";
    $query = 'MERGE (m:Member {id: {member}.id })
    SET m.name = {member}.name, m.avatar = {member}.avatar, m.joined_time = {member}.joined_time
    MERGE (city:City {name: {member}.city})
    MERGE (country:Country {code: {member}.country})
    MERGE (m)-[:LIVES_IN]->(city)
    MERGE (city)-[:IN_COUNTRY]->(country)';
    $p = ['member' => $member];
    $neoClient->sendCypherQuery($query, $p);

    //Inserting Groups the Member Belongs To
    $query = 'MATCH (m:Member {id: {member_id}})
    UNWIND {groups} as group
    MERGE (g:Group {id: group.id})
    SET g.name = group.name, g.description = group.description
    MERGE (o:Member {id: group.organizer.member_id})
    ON CREATE SET o.name = group.organizer.name
    MERGE (city:City {name: group.city})
    MERGE (cty:Country {code: upper(group.country)})
    MERGE (g)-[:GROUP_IN_CITY]->(city)
    MERGE (city)-[:IN_COUNTRY]->(cty)
    MERGE (m)-[:MEMBER_OF]->(g)
    FOREACH (topic IN group.topics |
    MERGE (t:Topic {id: topic.id})
    ON CREATE SET t.name = topic.name
    MERGE (t)-[:TAGS_GROUP]->(g))';
    $p = ['groups' => $groupMembers[$member['id']]['groups'], 'member_id' => $member['id']];
    echo 'Inserting groups for Member "' . $member['name'] . '"' . "\n";
    $neoClient->sendCypherQuery($query, $p);
}

// GetRsvps

$response = $meetupClient->getRSVPs(array('event_id' => $eventId));
$rsvps = [];
foreach ($response as $responseItem) {
    $rsvps[$responseItem['response']][] = [
        'id' => $responseItem['rsvp_id'],
        'member_id' => $responseItem['member']['member_id']
    ];
}
// Inserting Event RSVPS
$query = 'MATCH (e:Event {id: {event_id}})
UNWIND {rsvps}.yes as rsvp
MATCH (m:Member {id: rsvp.member_id})
MERGE (m)-[:PARTICIPATE {rsvp_id: rsvp.id}]->(e)
WITH e
UNWIND {rsvps}.no as rsvp
MATCH (m:Member {id: rsvp.member_id})
MERGE (m)-[:DECLINED {rsvp_id: rsvp.id}]->(e)';
$p = [
    'event_id' => $eventId,
    'rsvps' => $rsvps
];
echo 'Inserting RSVPs' . "\n";
$neoClient->sendCypherQuery($query, $p);


// Building meetup.html d3 demo

$template = '
<!DOCTYPE html>
<meta charset="utf-8">
<style>
text {
    font: 10px sans-serif;
}
</style>
<body>
<script src="http://d3js.org/d3.v3.min.js"></script>
<script>
var diameter = 960,
    format = d3.format(",d"),
    color = d3.scale.category20c();

var bubble = d3.layout.pack()
    .sort(null)
    .size([diameter, diameter])
    .padding(1.5);

var svg = d3.select("body").append("svg")
    .attr("width", diameter)
    .attr("height", diameter)
    .attr("class", "bubble");

';

$query = 'MATCH (event:Event {id: {event_id}})
MATCH (event)<-[:PARTICIPATE]-(m)
MATCH (m)-[:MEMBER_OF]->(group)<-[:TAGS_GROUP]-(t:Topic)
WITH t.name as topics, count(*) as c
WHERE c > 5
RETURN topics, c';
$p = ['event_id' => $eventId];
$result = $neoClient->sendCypherQuery($query, $p)->getResult();
$topics = $result->get('topics');
$counts = $result->get('c');
$i = 1;
$template .= '
var data = [';
foreach ($topics as $k => $topic) {
    $template .= '{"topic":"' . $topic . '", "count": ' . (int) $counts[$k] . '}';
    if ($i < count($topics)) {
        $template .= ',';
    }
    $i++;
}
$template .= '];';
$template .= '
var bobo = {};
bobo.name = "interests";
bobo.children = data;
var bubble = d3.layout.pack().sort(null).size([960,960]).padding(1.5);
var node = svg.selectAll(".node")
        .data(bubble.nodes(classes(bobo)))
               // .filter(function(d) { return !d.children; }))
        .enter().append("g")
        .attr("class", "node")
        .attr("transform", function(d) { return "translate(" + d.x + "," + d.y + ")"; });

node.append("title")
        .text(function(d) { return d.className + ": " + format(d.value); });

node.append("circle")
        .attr("r", function(d) { return d.r; })
        .style("fill", function(d) { return color(d.packageName); });

node.append("text")
        .attr("dy", ".3em")
        .style("text-anchor", "middle")
        .text(function(d) { var txt = undefined !== d.className ? d.className: "topic"; return txt.substring(0, d.r / 3); });


// Returns a flattened hierarchy containing all leaf nodes under the root.
function classes(root) {
    var classes = [];

    function recurse(name, node) {
        if (node.children) node.children.forEach(function(child) { recurse(node.topic, child); });
        else classes.push({packageName: node.topic, className: node.topic, value: node.count});
    }

    recurse(null, root);
    return {children: classes};
}

d3.select(self.frameElement).style("height", diameter + "px");';
$template .= '</script>';
file_put_contents('meetup.html', $template);
echo 'Import Done, Enjoy !' . "\n";
