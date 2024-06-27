## installation

Add the custom repository to your composer.json:

```php
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:snoke/doctrine-softdelete.git"
        }
    ],
```

checkout library `git req snoke/doctrine-sofftdelete:dev-main`

## usage

the timetsamps require your entity to use the HasLifecycleCallbacks-Annotation

```php
use Doctrine\ORM\Mapping as ORM;
use Snoke\DoctrineSoftDelete\Trait\SoftDelete;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity]
class User
{
    use SoftDelete;
    ...
```

update database schema: `php bin/console make:migration` and `php bin/console do:mi:mi`

## example

The following example would not delete the entry from the database but will store the current timestamp in the deletedAt column:

```php
$user->delete();
$entityManager->flush();
var_dump($user->getDeletedAt());
```
```php
object(DateTimeImmutable)#674 (3) {
  ["date"]=>
  string(26) "2024-06-26 16:09:40.054742"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(13) "Europe/Berlin"
}

```

## Cascade
SoftDelete also respect hard delete cascade annotations!

Also you can use the Cascade-Interface to mark soft delete cascades
