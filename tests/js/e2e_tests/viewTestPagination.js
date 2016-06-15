describe("viewTestPagination", function() {

  it("display all tests", function() {
    browser.get('index.php?project=Trilinos&date=2011-07-22');
    element(by.linkText('Windows_NT-MSVC10-SERIAL_DEBUG_DEV')).click();

    // First, verify the expected number of builds
    expect(element(by.id('numbuilds')).getText()).toBe('Number of SubProjects: 36');
    // Note: A maximum of 10 builds are displayed at a time
    expect(element.all(by.repeater('build in buildgroup.pagination.filteredBuilds')).count()).toBe(10);

    // Next, click on a specific test
    var build_failures = element.all(by.binding('build.test.notrun'));
    expect(build_failures.count()).toBe(5);
    var test = build_failures.get(2);
    expect(test.getText()).toBe('29');
    test.click();
    browser.waitForAngular();

    // Make sure the expected number of tests are displayed
    expect(element.all(by.repeater('test in pagination.filteredTests')).count()).toBe(25);

    // Now display all items
    element(by.name('itemsPerPage')).$('[value="-1"]').click();

    // Make sure the expected number of tests are displayed
    expect(element.all(by.repeater('test in pagination.filteredTests')).count()).toBe(29);

  });
});
