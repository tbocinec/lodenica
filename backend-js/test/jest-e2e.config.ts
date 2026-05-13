import type { Config } from 'jest';

const config: Config = {
  rootDir: '..',
  moduleFileExtensions: ['js', 'json', 'ts'],
  testEnvironment: 'node',
  testRegex: '.*\\.e2e-spec\\.ts$',
  transform: {
    '^.+\\.(t|j)s$': 'ts-jest',
  },
  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/src/$1',
  },
};

export default config;
