Ark & Noid (module for Omeka S)
===============================

[Ark & Noid] is a module for [Omeka S] that creates and manages [ark identifiers].

This [Omeka S] module is a port of the [Ark & Noid plugin] for [Omeka] by [BibLibre]
and intends to provide the same features as the original plugin.

See the full documentation about [ark and noid management] in Omeka, similar to
this version for Omeka S.


Installation
------------

The module uses an external library, [Noid], so use the release zip to install
it, or use and init the source.

* From the zip

Download the last release [`Ark.zip`] from the list of releases (the master does
not contain the dependency), and uncompress it in the `modules` directory.

* From the source and for development

If the module was installed from the source, rename the name of the folder of
the module to `Ark`, go to the root module, and run:

```
    npm install
    gulp
```


## TODO

- Manage the policy statement by item.
- Let the choice to link ark to the file or to the record.


Warning
-------

Use it at your own risk.

It’s always recommended to backup your files and your databases and to check
your archives regularly so you can roll back if needed.


Troubleshooting
---------------

See online issues on the [module issues] page on GitHub.


License
-------

This module is published under the [CeCILL v2.1] licence, compatible with
[GNU/GPL] and approved by [FSF] and [OSI].

In consideration of access to the source code and the rights to copy, modify and
redistribute granted by the license, users are provided only with a limited
warranty and the software’s author, the holder of the economic rights, and the
successive licensors only have limited liability.

In this respect, the risks associated with loading, using, modifying and/or
developing or reproducing the software by the user are brought to the user’s
attention, given its Free Software status, which may make it complicated to use,
with the result that its use is reserved for developers and experienced
professionals having in-depth computer knowledge. Users are therefore encouraged
to load and test the suitability of the software as regards their requirements
in conditions enabling the security of their systems and/or data to be ensured
and, more generally, to use and operate it in the same conditions of security.
This Agreement may be freely reproduced and published, provided it is not
altered, and that no provisions are either added or removed herefrom.


Contact
-------

Current maintainers:

* Daniel Berthereau (see [Daniel-KM] on GitHub)
* BibLibre

First version of this plugin has been built for [Mines ParisTech]. The upgrade
for Omeka S has been built for [Paris Sciences et Lettres (PSL)].


Copyright
---------

* Copyright Daniel Berthereau, 2015-2018
* Copyright BibLibre, 2016-2017


[Ark & Noid]: https://github.com/Daniel-KM/Omeka-S-module-Ark
[Omeka S]: https://omeka.org/s
[ark identifiers]: https://n2t.net/e/ark_ids.html
[Ark & Noid plugin]: https://github.com/Daniel-KM/Omeka-plugin-ArkAndNoid
[Omeka]: https://omeka.org/classic
[BibLibre]: https://github.com/biblibre
[ark and noid management]: https://github.com/Daniel-KM/Omeka-plugin-ArkAndNoid
[module issues]: https://github.com/Daniel-KM/Omeka-S-module-CleanUrl/issues
[CeCILL v2.1]: https://www.cecill.info/licences/Licence_CeCILL_V2.1-en.html
[GNU/GPL]: https://www.gnu.org/licenses/gpl-3.0.html
[FSF]: https://www.fsf.org
[OSI]: http://opensource.org
[Mines ParisTech]: https://patrimoine.mines-paristech.fr
[Paris Sciences et Lettres (PSL)]: https://bibnum.explore.univ-psl.fr
[Daniel-KM]: https://github.com/Daniel-KM "Daniel Berthereau"
