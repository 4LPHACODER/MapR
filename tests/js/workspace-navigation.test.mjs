import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { isNavigationActive, workspaceNavigation } from '../../resources/js/shared/navigation.js';

test('BDRRM resident records remain limited to barangay leadership', () => {
    const paths = role => workspaceNavigation('bdrrm', role).items.map(item => item.to);
    assert.ok(!paths('bdrrm').includes('/residents'));
    assert.ok(!paths('').includes('/residents'));
    assert.ok(paths('barangay_secretary').includes('/residents'));
    assert.ok(paths('barangay_captain').includes('/residents'));
});

test('every mobile destination is present in its workspace navigation', () => {
    for (const workspace of ['resident', 'bdrrm', 'assessor', 'cdrrmo']) {
        const config = workspaceNavigation(workspace);
        for (const path of config.mobile) assert.ok(config.items.some(item => item.to === path), `${workspace}: ${path}`);
        assert.equal(new Set(config.items.map(item => item.key || item.to)).size, config.items.length);
    }
});

test('all configured destinations resolve to existing workspace routes', () => {
    for (const [workspace, entry] of Object.entries({ resident: 'resident', bdrrm: 'bdrrm', assessor: 'rdana', cdrrmo: 'cdrrmo' })) {
        const source = readFileSync(new URL(`../../resources/js/${entry}.js`, import.meta.url), 'utf8');
        const paths = [...source.matchAll(/path: '([^']+)'/g)].map(match => match[1]);
        for (const item of workspaceNavigation(workspace, 'barangay_secretary').items) {
            const path = typeof item.to === 'string' ? item.to : item.to.path;
            assert.ok(paths.includes(path) || paths.includes(`${path}/:id?`), `${workspace}: ${path}`);
        }
    }
});

test('nested resident report routes select only their matching section', () => {
    const items = workspaceNavigation('resident').items;
    const selected = path => items.filter(item => isNavigationActive(item, { path, query: {} })).map(item => item.to);
    assert.deepEqual(selected('/'), ['/']);
    assert.deepEqual(selected('/report/12'), ['/report']);
    assert.deepEqual(selected('/reports/12/track'), ['/reports']);
});

test('impact creation and the assessment map have distinct selected states', () => {
    const items = workspaceNavigation('cdrrmo').items;
    const selected = query => items.filter(item => isNavigationActive(item, { path: '/map', query })).map(item => item.key || item.to);
    assert.deepEqual(selected({}), ['/map']);
    assert.deepEqual(selected({ mode: 'estimate' }), ['impact']);
});
